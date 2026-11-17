<?php
declare(strict_types=1);

namespace Ablet\Service;

use Ablet\Domain\Adaptation\RuleEngine;
use Ablet\Domain\Agent\OutputValidator;
use Ablet\Domain\Agent\PromptCompiler;
use Ablet\Domain\Srl\StateMachine as SM;
use Ablet\Llm\Gateway;
use Ablet\Llm\ProviderException;
use Ablet\Repository\SessionRepository as Repo;
use Ablet\Support\Database;

/**
 * Orchestrazione di un turno: evento dello studente → stato → LLM → convalida → transizioni → adattamento.
 * Nessun contenuto generato raggiunge lo studente prima dei controlli (per questo non si usa lo streaming).
 */
final class TurnService
{
    private const TRANSITION_ACTIONS = ['start' => 'start', 'begin' => 'begin_performance', 'replan' => 'return_to_planning', 'to_reflection' => 'begin_reflection',
        'pause' => 'pause', 'resume' => 'resume', 'close_complete' => 'close_complete', 'close_incomplete' => 'close_incomplete', 'safety_release' => 'safety_release'];
    private const NOTES = [
        'start' => 'Lo studente ha avviato la sessione. Presentati come sistema di IA di supporto allo studio e apri la fase di pianificazione.',
        'begin' => 'Lo studente ha scelto di iniziare il lavoro. Indica il passo corrente.',
        'replan' => 'Lo studente è tornato alla pianificazione. Chiedi che cosa vuole modificare nel piano.',
        'to_reflection' => 'Lo studente è passato alla riflessione. Confronta previsione e dati disponibili e chiedi una valutazione rispetto ai criteri.',
        'resume' => 'Lo studente ha ripreso dopo una pausa. Ricorda obiettivo e passo corrente in una frase.',
        'safety_release' => 'Lo studente ha scelto di riprendere l\'attività dopo una sospensione. Riprendi con delicatezza dal passo corrente.',
        'step_done' => 'Lo studente segnala di aver completato il passo. Dai un feedback descrittivo sul processo e indica il passo successivo, se c\'è.',
        'step_not_done' => 'Lo studente segnala di NON aver completato il passo. Esplora l\'ostacolo senza attribuzioni automatiche.',
        'hint' => 'Lo studente chiede un indizio (vedi hints_used del passo corrente: l\'indizio deve essere graduato e non rivelare la soluzione).',
        'difficulty' => 'Lo studente segnala una difficoltà. Aiutalo a localizzarla.',
        'strategy_change' => 'Lo studente vuole cambiare strategia. Chiedi il confronto esplicito fra la strategia precedente e la nuova.',
        'accept_proposal' => 'Lo studente ha accettato la proposta di modifica del supporto. Prosegui adeguando la conduzione al nuovo stato.',
        'decline_proposal' => 'Lo studente ha rifiutato la proposta di modifica del supporto. Rispetta la scelta e prosegui.',
    ];

    public static function lock(int $sid, callable $fn): mixed
    {
        $pdo = Database::pdo();
        $name = "ablet_s_$sid";
        $st = $pdo->prepare('SELECT GET_LOCK(?, 20)');
        $st->execute([$name]);
        if ((int)$st->fetchColumn() !== 1) {
            throw new \DomainException('sessione_occupata');
        }
        try {
            return $fn();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$name]);
        }
    }

    private static function seen(int $sid, ?string $cid): bool
    {
        if ($cid === null) { return false; }
        $st = Database::pdo()->prepare('SELECT 1 FROM events WHERE session_id = ? AND client_event_id = ?');
        $st->execute([$sid, $cid]);
        return (bool)$st->fetchColumn();
    }

    private static function saveMessage(int $sid, string $role, string $content, string $phase, array $opt = []): int
    {
        $pdo = Database::pdo();
        $reply = null;
        if ($role === 'student') {
            $st = $pdo->prepare("SELECT MAX(id) FROM messages WHERE session_id = ? AND role = 'agent'");
            $st->execute([$sid]);
            $reply = $st->fetchColumn() ?: null;
        }
        $pdo->prepare('INSERT INTO messages (session_id, role, modality, content, phase, in_reply_to, is_simulated, llm_meta) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$sid, $role, $opt['modality'] ?? 'text', $content, $phase, $reply, (int)($opt['simulated'] ?? 0),
                isset($opt['meta']) ? json_encode($opt['meta'], JSON_UNESCAPED_UNICODE) : null]);
        return (int)$pdo->lastInsertId();
    }

    /** @return array{notice:?string} */
    public static function studentMessage(int $sid, string $text, string $modality = 'text', ?string $cid = null, string $purpose = 'turno'): array
    {
        return self::lock($sid, static function () use ($sid, $text, $modality, $cid, $purpose): array {
            if (self::seen($sid, $cid)) { return ['notice' => 'duplicato']; }
            $c = SessionView::context($sid);
            if (SM::isClosed($c['state']) || $c['state'] === SM::CREATED) {
                throw new \DomainException('sessione_non_attiva');
            }
            $phase = $c['state'] === SM::SAFETY_HOLD ? SM::SAFETY_HOLD : $c['phase'];
            $mid = self::saveMessage($sid, 'student', $text, $phase, ['modality' => $modality === 'voice' ? 'voice' : 'text']);
            Repo::recordEvent($sid, 'student', 'message_sent', ['message_id' => $mid, 'modality' => $modality], $cid);

            if ($c['state'] === SM::SAFETY_HOLD) {
                self::saveMessage($sid, 'agent', SafetyNet::MSG_HOLD, SM::SAFETY_HOLD, ['meta' => ['fixed' => true]]);
                return ['notice' => null];
            }
            $risk = SafetyNet::classify($text);
            if ($risk !== 'ok') {
                self::hold($sid, $risk, 'system', $mid);
                return ['notice' => 'sospensione_di_sicurezza'];
            }
            if ($c['state'] === SM::PAUSED || !$c['agent_enabled'] || !$c['protocol_version_id']) {
                return ['notice' => null];
            }
            return self::agentTurn($sid, ['type' => 'message', 'message_id' => $mid, 'text' => $text], $purpose);
        });
    }

    private static function hold(int $sid, string $risk, string $actor, ?int $mid): void
    {
        Repo::transition($sid, 'safety_hold', $actor);
        Repo::recordEvent($sid, $actor, 'safety_signal', ['level' => $risk, 'message_id' => $mid]);
        self::saveMessage($sid, 'agent', $risk === 'emergency' ? SafetyNet::MSG_EMERGENCY : SafetyNet::MSG_DISTRESS, SM::SAFETY_HOLD, ['meta' => ['fixed' => true, 'safety' => $risk]]);
    }

    /** Azioni da pulsante o da pannello del piano. @return array{notice:?string} */
    public static function action(int $sid, string $action, array $p = [], ?string $cid = null, string $purpose = 'turno'): array
    {
        return self::lock($sid, static function () use ($sid, $action, $p, $cid, $purpose): array {
            if (self::seen($sid, $cid)) { return ['notice' => 'duplicato']; }
            $pdo = Database::pdo();
            $c = SessionView::context($sid);
            $agent = $c['agent_enabled'] && $c['protocol_version_id'];
            $speak = false;

            if (isset(self::TRANSITION_ACTIONS[$action])) {
                $r = Repo::transition($sid, self::TRANSITION_ACTIONS[$action], 'student', null, $cid);
                if (!$r['ok']) {
                    throw new \DomainException($r['error'] . (isset($r['detail']) && is_array($r['detail']) ? ':' . implode(',', array_map('strval', array_filter($r['detail'], 'is_scalar'))) : ''));
                }
                if ($action === 'begin' || (in_array($action, ['resume', 'safety_release'], true) && $r['state'] === SM::PERFORMANCE)) {
                    self::activateNext($sid, 'system');
                }
                if (SM::isClosed((string)($r['state'] ?? '')) && $c['pending_adaptation_id']) {
                    // Proposta rimasta senza risposta: decade con la chiusura (registrato), non si applica e non resta visibile.
                    $pdo->prepare('UPDATE sessions SET pending_adaptation_id = NULL WHERE id = ?')->execute([$sid]);
                    Repo::recordEvent($sid, 'system', 'proposal_expired', ['adaptation_id' => (int)$c['pending_adaptation_id']]);
                }
                $speak = isset(self::NOTES[$action]);
            } elseif (in_array($action, ['step_done', 'step_not_done', 'hint', 'difficulty', 'strategy_change'], true)) {
                if ($c['state'] !== SM::PERFORMANCE) { throw new \DomainException('azione_non_disponibile_in_questa_fase'); }
                $goal = SessionView::currentGoal($c);
                if ($action === 'step_done' || $action === 'step_not_done') {
                    if (!$goal) { throw new \DomainException('nessun_passo_attivo'); }
                    $done = $action === 'step_done';
                    $pdo->prepare('UPDATE session_goals SET status = ?, ended_at = CURRENT_TIMESTAMP(3) WHERE id = ?')->execute([$done ? 'completed' : 'not_completed', $goal['id']]);
                    Repo::recordEvent($sid, 'student', $done ? 'step_completed' : 'step_not_completed', ['goal_id' => (int)$goal['id'], 'hints_used' => (int)$goal['hints_used']], $cid);
                    self::activateNext($sid, 'system');
                } elseif ($action === 'hint') {
                    if ($goal) { $pdo->prepare('UPDATE session_goals SET hints_used = hints_used + 1 WHERE id = ?')->execute([$goal['id']]); }
                    Repo::recordEvent($sid, 'student', 'hint_requested', ['goal_id' => $goal['id'] ?? null], $cid);
                } elseif ($action === 'difficulty') {
                    Repo::recordEvent($sid, 'student', 'difficulty_flagged', ['goal_id' => $goal['id'] ?? null], $cid);
                    Repo::recordEvent($sid, 'student', 'attempt_failed', ['goal_id' => $goal['id'] ?? null]);
                } else {
                    $new = trim((string)($p['strategy'] ?? ''));
                    Repo::recordEvent($sid, 'student', 'strategy_changed', ['from' => $c['srl']['strategy'] ?? null, 'to' => $new ?: null], $cid);
                    if ($new !== '') { Repo::mergeSlots($sid, ['strategy' => mb_substr($new, 0, 500)]); }
                }
                self::adapt($sid);
                $speak = true;
            } elseif ($action === 'accept_proposal' || $action === 'decline_proposal') {
                if (!$c['pending_adaptation_id']) { throw new \DomainException('nessuna_proposta'); }
                $st = $pdo->prepare('SELECT * FROM adaptations WHERE id = ? AND session_id = ?');
                $st->execute([$c['pending_adaptation_id'], $sid]);
                $a = $st->fetch();
                $pdo->prepare('UPDATE sessions SET pending_adaptation_id = NULL WHERE id = ?')->execute([$sid]);
                Repo::recordEvent($sid, 'student', $action === 'accept_proposal' ? 'proposal_accepted' : 'proposal_declined', ['adaptation_id' => (int)$a['id'], 'action' => $a['action']], $cid);
                if ($action === 'accept_proposal' && $a['action'] !== 'suggest_alternative_strategy') {
                    Repo::recordAdaptation($sid, ['source' => 'preferenza_studente', 'rule_key' => $a['rule_key'], 'rule_snapshot' => json_decode($a['rule_snapshot'] ?? 'null', true),
                        'action' => $a['action'], 'outcome' => 'applicata', 'indicators' => json_decode($a['indicators'], true), 'evidence_event_ids' => json_decode($a['evidence_event_ids'], true),
                        'prev_support_level' => (int)$a['prev_support_level'], 'new_support_level' => (int)$a['new_support_level'],
                        'prev_step_size' => (int)$a['prev_step_size'], 'new_step_size' => (int)$a['new_step_size']]);
                }
                $speak = !SM::isClosed($c['state']) && in_array($c['state'], SM::PHASES, true);
            } elseif ($action === 'set_slot') {
                if (!in_array($p['slot'] ?? '', SM::FORETHOUGHT_SLOTS, true)) { throw new \DomainException('slot_non_valido'); }
                $v = $p['value'] ?? '';
                if (in_array($p['slot'], ['time_estimate_min', 'available_time_min'], true) &&
                    (filter_var($v, FILTER_VALIDATE_INT) === false || (int)$v < 1 || (int)$v > 600)) {
                    throw new \DomainException('tempo_non_valido');
                }
                $v = in_array($p['slot'], ['expected_difficulty', 'self_efficacy', 'time_estimate_min', 'available_time_min'], true) ? max(0, min(600, (int)$v)) : mb_substr(trim((string)$v), 0, 500);
                Repo::mergeSlots($sid, [$p['slot'] => $v]);
                self::assess($sid, [$p['slot'] => $v], null);
                Repo::recordEvent($sid, 'student', 'slot_set', ['slot' => $p['slot']], $cid);
            } elseif ($action === 'add_goal') {
                $d = mb_substr(trim((string)($p['description'] ?? '')), 0, 300);
                if ($d === '' || $c['phase'] !== SM::FORETHOUGHT) { throw new \DomainException('micro_obiettivo_non_valido'); }
                if (count(array_filter($c['goals'], static fn($g) => $g['status'] !== 'dropped')) >= 10) { throw new \DomainException('troppi_micro_obiettivi'); }
                $gid = Repo::addGoal($sid, $d, isset($p['minutes']) && (int)$p['minutes'] > 0 ? min(600, (int)$p['minutes']) : null);
                Repo::recordEvent($sid, 'student', 'goal_added', ['goal_id' => $gid], $cid);
            } elseif ($action === 'set_goal_time') {
                $minutes = $p['minutes'] ?? null;
                if ($c['state'] !== SM::FORETHOUGHT || filter_var($minutes, FILTER_VALIDATE_INT) === false || (int)$minutes < 1 || (int)$minutes > 600) {
                    throw new \DomainException('tempo_micro_obiettivo_non_valido');
                }
                $goalId = (int)($p['goal_id'] ?? 0);
                $matches = array_filter($c['goals'], static fn($g) => (int)$g['id'] === $goalId && $g['status'] === 'pending');
                if (!$matches) { throw new \DomainException('micro_obiettivo_non_valido'); }
                $pdo->prepare('UPDATE session_goals SET estimated_minutes = ? WHERE id = ? AND session_id = ?')->execute([(int)$minutes, $goalId, $sid]);
                Repo::recordEvent($sid, 'student', 'goal_time_set', ['goal_id' => $goalId, 'minutes' => (int)$minutes], $cid);
            } elseif ($action === 'drop_goal') {
                $pdo->prepare("UPDATE session_goals SET status = 'dropped' WHERE id = ? AND session_id = ? AND status = 'pending'")->execute([(int)($p['goal_id'] ?? 0), $sid]);
                Repo::recordEvent($sid, 'student', 'goal_dropped', ['goal_id' => (int)($p['goal_id'] ?? 0)], $cid);
            } elseif ($action === 'self_assessment') {
                $kinds = ['sicurezza' => [1, 5], 'valutazione_risultato' => [1, 5], 'autoefficacia' => [1, 5], 'reazione_emotiva' => null, 'studio_fuori_app_min' => [0, 600]];
                $k = (string)($p['kind'] ?? '');
                if (!array_key_exists($k, $kinds)) { throw new \DomainException('autovalutazione_non_valida'); }
                $num = $kinds[$k] ? max($kinds[$k][0], min($kinds[$k][1], (int)($p['value'] ?? 0))) : null;
                $goal = SessionView::currentGoal($c);
                $pdo->prepare('INSERT INTO self_assessments (session_id, goal_id, kind, value_num, value_text, scale_min, scale_max) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$sid, $k === 'sicurezza' ? ($p['goal_id'] ?? $goal['id'] ?? null) : null, $k, $num, $kinds[$k] ? null : mb_substr((string)($p['value'] ?? ''), 0, 300), $kinds[$k][0] ?? null, $k === 'studio_fuori_app_min' ? null : ($kinds[$k][1] ?? null)]);
                Repo::recordEvent($sid, 'student', 'self_assessment', ['kind' => $k], $cid);
            } elseif ($action === 'material_opened') {
                Repo::recordEvent($sid, 'student', 'material_reopened', ['material_id' => (int)($p['material_id'] ?? 0)], $cid);
            } else {
                throw new \DomainException('azione_sconosciuta');
            }
            return ($speak && $agent) ? self::agentTurn($sid, ['type' => 'action', 'action' => $action, 'note' => self::NOTES[$action] ?? ''], $purpose) : ['notice' => null];
        });
    }

    private static function activateNext(int $sid, string $actor): void
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM session_goals WHERE session_id = ? AND status = 'active') a, (SELECT MIN(id) FROM session_goals WHERE session_id = ? AND status = 'pending') n");
        $st->execute([$sid, $sid]);
        $r = $st->fetch();
        if ((int)$r['a'] === 0 && $r['n']) {
            $pdo->prepare("UPDATE session_goals SET status = 'active', started_at = CURRENT_TIMESTAMP(3) WHERE id = ?")->execute([$r['n']]);
            Repo::recordEvent($sid, $actor, 'step_started', ['goal_id' => (int)$r['n']]);
        }
    }

    private static function assess(int $sid, array $slots, ?int $mid): void
    {
        $map = ['self_efficacy' => ['autoefficacia', 1, 5], 'expected_difficulty' => ['difficolta_attesa', 1, 5], 'time_estimate_min' => ['stima_tempo_min', null, null]];
        foreach (array_intersect_key($slots, $map) as $k => $v) {
            Database::pdo()->prepare('INSERT INTO self_assessments (session_id, kind, value_num, scale_min, scale_max, message_id) VALUES (?,?,?,?,?,?)')
                ->execute([$sid, $map[$k][0], (int)$v, $map[$k][1], $map[$k][2], $mid]);
        }
    }

    /** Valuta le regole del protocollo sugli eventi osservati e registra l'eventuale decisione. */
    public static function adapt(int $sid): ?array
    {
        $c = SessionView::context($sid);
        $rules = $c['cfg']['adaptation_rules'] ?? [];
        if ($rules === [] || $c['pending_adaptation_id']) { return null; }
        $pdo = Database::pdo();
        $pr = $pdo->prepare('SELECT preferences FROM student_models WHERE student_id = ?');
        $pr->execute([$c['student_id']]);
        $model = ['support_level' => (int)$c['support_level'], 'step_size' => (int)$c['step_size'], 'preferences' => json_decode((string)$pr->fetchColumn(), true) ?: []];
        $d = RuleEngine::evaluate($rules, RuleEngine::indicators(Repo::observedEvents($sid)), $model, Repo::adaptationHistory($sid));
        if ($d === null) { return null; }
        $id = Repo::recordAdaptation($sid, $d);
        Repo::recordEvent($sid, 'system', 'adaptation', ['adaptation_id' => $id, 'rule' => $d['rule_key'], 'outcome' => $d['outcome']]);
        if ($d['outcome'] === 'proposta') {
            $pdo->prepare('UPDATE sessions SET pending_adaptation_id = ? WHERE id = ?')->execute([$id, $sid]);
        }
        return $d + ['id' => $id];
    }

    private static function extractJson(string $raw): string
    {
        $raw = trim($raw);
        $a = strpos($raw, '{');
        $b = strrpos($raw, '}');
        return ($a !== false && $b !== false && $b > $a) ? substr($raw, $a, $b - $a + 1) : $raw;
    }

    /** @return array{notice:?string} */
    private static function agentTurn(int $sid, array $trigger, string $purpose): array
    {
        \Ablet\Llm\Http::setBudget((float)(\Ablet\Support\Env::get('LLM_TURN_BUDGET_S', '50')));
        $pdo = Database::pdo();
        $c = SessionView::context($sid);
        if (!in_array($c['state'], SM::PHASES, true)) { return ['notice' => null]; }

        $prompt = $c['compiled_prompt'] ?: PromptCompiler::compile($c['cfg'])['prompt'];
        $state = SessionView::forAgent($c, array_diff_key($trigger, ['text' => 1]));
        $h = $pdo->prepare("SELECT id, role, content FROM (SELECT id, role, content FROM messages WHERE session_id = ? AND role IN ('student','agent') ORDER BY id DESC LIMIT 24) t ORDER BY id");
        $h->execute([$sid]);
        $history = $h->fetchAll();
        if (!empty($c['cfg']['prompt_budget']['economy'])) $history = array_slice($history, -8);
        $ids = $pdo->prepare("SELECT id FROM messages WHERE session_id = ? AND role = 'student'");
        $ids->execute([$sid]);
        $studentIds = array_map('intval', $ids->fetchAll(\PDO::FETCH_COLUMN));
        $fp = $pdo->prepare('SELECT age_band, strengths, accessibility_needs, constraints_text, preferences FROM functional_profiles WHERE student_id = ? ORDER BY version_no DESC LIMIT 1');
        $fp->execute([$c['student_id']]);
        $profile = $fp->fetch() ?: null;
        $age = $profile['age_band'] ?? null;
        $turn = PromptCompiler::buildTurn($prompt, $state, $c['materials'], $history, $profile);
        $schema = (string)file_get_contents(ABLET_ROOT . '/resources/schema/agent_output.schema.json');
        $tail = $turn['context'] . "\n\n" . \Ablet\Domain\Agent\Delivery::instructions($age) . "\n\n" . (($trigger['note'] ?? '') !== '' ? "EVENTO (generato dal server): {$trigger['note']}\n" : '')
            . "Rispondi ora con UN SOLO oggetto JSON conforme a questo schema, senza testo prima o dopo:\n" . $schema;
        $msgs = Gateway::normalize(array_merge($turn['messages'], [['role' => 'user', 'content' => $tail]]));

        $cfg = Gateway::configFor((int)$c['owner_id'], isset($c['cfg']['provider']['provider_config_id']) ? (int)$c['cfg']['provider']['provider_config_id'] : null);
        if ($cfg['provider_key'] !== 'demo' && Consent::missing((int)$c['student_id'], (int)$c['owner_id'], ['invio_provider_esterno']) !== []) {
            Repo::recordEvent($sid, 'system', 'provider_blocked_consent');
            self::saveMessage($sid, 'agent', 'Per ora non posso risponderti: manca un\'autorizzazione. Il tuo lavoro è salvato. Puoi chiedere a un adulto di avvisare il tuo psicologo.', $c['phase'], ['meta' => ['fixed' => true, 'fallback' => 'autorizzazione_mancante']]);
            return ['notice' => 'autorizzazione_mancante'];
        }
        $budget = \Ablet\Domain\Agent\PromptBudget::estimate($turn['system'] . json_encode($msgs, JSON_UNESCAPED_UNICODE), $c['cfg'], $cfg);
        if ($cfg['provider_key'] !== 'demo' && !$budget['fits']) {
            Repo::recordEvent($sid, 'system', 'prompt_budget_exceeded', ['estimated_tokens' => $budget['estimated_input_tokens'], 'context_limit' => $budget['context_tokens']]);
            self::saveMessage($sid, 'agent', 'Il contenuto da inviare è troppo lungo per il limite configurato. Il tuo lavoro è salvato. Chiedi al professionista di ridurre il materiale o usare un protocollo in modalità economica.', $c['phase'], ['meta' => ['fixed' => true]]);
            return ['notice' => 'prompt_troppo_lungo'];
        }
        $params = array_intersect_key($c['cfg']['provider']['params'] ?? [], ['temperature' => 1, 'max_output_tokens' => 1]);
        $params['_demo'] = ['state' => $state, 'text' => $trigger['text'] ?? '', 'message_id' => $trigger['message_id'] ?? null];
        $materialTexts = array_column($c['materials'], 'content_text');

        $data = null; $meta = ['attempts' => 0]; $notice = null; $res = null; $spare = null;
        for ($i = 0; $i < 2 && $data === null; $i++) {
            $meta['attempts']++;
            try {
                $res = Gateway::call($cfg, $purpose, $sid, $turn['system'], $msgs, $params);
            } catch (ProviderException $e) {
                Repo::recordEvent($sid, 'system', 'provider_error', ['kind' => $e->kind]);
                $notice = match ($e->kind) {
                    'cancelled' => 'annullato',
                    'rate_limited' => 'provider_rate_limited',
                    'service_unavailable' => 'provider_service_unavailable',
                    'timeout' => 'provider_timeout',
                    'auth', 'config', 'budget' => 'provider_configurazione',
                    default => 'provider_non_disponibile',
                };
                $meta['provider_error'] = ['kind' => $e->kind, 'http_status' => $e->httpStatus, 'retry_after' => $e->retryAfter];
                break;
            }
            $v = OutputValidator::validate(self::extractJson($res['text']), $studentIds);
            if (!$v['ok']) {
                Repo::recordEvent($sid, 'system', 'invalid_output', ['errors' => array_slice($v['errors'], 0, 5)]);
                $notice = 'output_non_valido';
                $msgs[count($msgs) - 1]['content'] .= "\n\nIl tuo output precedente non era valido (" . implode(', ', array_slice($v['errors'], 0, 3)) . '). Rispondi solo con il JSON richiesto.';
                continue;
            }
            $g = OutputGuard::check($v['data']['student_message'], $materialTexts);
            if ($g['block'] !== []) {
                Repo::recordEvent($sid, 'system', 'output_blocked', ['reasons' => $g['block']]);
                $notice = 'output_bloccato';
                $msgs[count($msgs) - 1]['content'] .= "\n\nIl tuo messaggio precedente violava le regole fondamentali (" . implode(', ', $g['block']) . '). Riformula rispettandole.';
                continue;
            }
            $v['data']['student_message'] = \Ablet\Domain\Agent\Delivery::sanitize($v['data']['student_message']);
            $issues = \Ablet\Domain\Agent\Delivery::issues($v['data']['student_message'], $age);
            $retry = array_values(array_intersect($issues, ['troppo_lungo', 'troppe_domande']));
            if ($retry !== [] && $i === 0) {
                // Un solo nuovo tentativo per la forma parlata; il messaggio valido resta di riserva.
                $spare = [$v['data'], $g['flags'], $res, $issues];
                Repo::recordEvent($sid, 'system', 'delivery_retry', ['issues' => $retry]);
                $msgs[count($msgs) - 1]['content'] .= "\n\nIl tuo messaggio era poco adatto all'ascolto (" . implode(', ', $retry) . '). Riscrivilo più breve, con una sola richiesta finale.';
                continue;
            }
            $data = $v['data'];
            $meta['flags'] = $g['flags'];
            $meta['delivery'] = $issues;
            $notice = null;
        }
        if ($data === null && $spare !== null && $notice !== 'annullato') {
            [$data, $meta['flags'], $res, $meta['delivery']] = $spare;
            $notice = null;
        }

        if ($data === null) {
            if ($notice !== 'annullato') {
                $fallbackText = match ($notice) {
                    'provider_rate_limited' => 'Devo aspettare un po’ prima di risponderti. Quello che hai detto è salvato. Intanto, quale piccolo passo puoi fare da solo?',
                    'provider_timeout', 'provider_service_unavailable' => 'La risposta sta tardando. Quello che hai detto è salvato. Quale piccolo passo puoi continuare da solo?',
                    default => OutputValidator::fallbackMessage(),
                };
                self::saveMessage($sid, 'agent', $fallbackText, $c['phase'], ['meta' => $meta + ['fallback' => $notice]]);
            }
            return ['notice' => $notice];
        }
        $meta += ['provider' => $res['provider_key'], 'model' => $res['model_name'], 'is_fallback_provider' => $res['is_fallback'], 'proposed_action' => $data['proposed_action'],
            'activity_mode' => $c['activity_mode'], 'content_source' => $state['content_source'], 'llm_phase' => $data['current_phase'], 'suggested_support_level' => $data['suggested_support_level'], 'evidence_message_ids' => $data['evidence_message_ids'], 'safety_status' => $data['safety_status']];

        if ($data['safety_status'] !== 'ok') {
            self::hold($sid, $data['safety_status'], 'agent', $trigger['message_id'] ?? null);
            return ['notice' => 'sospensione_di_sicurezza'];
        }
        $u = $data['proposed_state_update'];
        if (!empty($u['slots'])) {
            Repo::mergeSlots($sid, $u['slots']);
            self::assess($sid, $u['slots'], $data['evidence_message_ids'][0] ?? null);
        }
        if (!empty($u['micro_goals']) && $c['phase'] === SM::FORETHOUGHT) {
            $open = array_filter($c['goals'], static fn($x) => $x['status'] !== 'dropped');
            $have = array_map(static fn($x) => mb_strtolower($x['description']), $open);
            $room = $state['max_micro_goals'] - count($open);
            foreach ($u['micro_goals'] as $mg) {
                $desc = mb_substr(trim($mg['description']), 0, 300);
                if (in_array(mb_strtolower($desc), $have, true)) {
                    // Una stima precisata in un turno successivo aggiorna il passo, senza duplicarlo.
                    foreach ($open as $existing) {
                        if ($existing['status'] === 'pending' && mb_strtolower($existing['description']) === mb_strtolower($desc) && isset($mg['estimated_minutes'])) {
                            $pdo->prepare('UPDATE session_goals SET estimated_minutes = ? WHERE id = ? AND session_id = ?')
                                ->execute([$mg['estimated_minutes'], $existing['id'], $sid]);
                        }
                    }
                    continue;
                }
                if ($room <= 0) { continue; }
                Repo::addGoal($sid, $desc, isset($mg['estimated_minutes']) && is_int($mg['estimated_minutes']) && $mg['estimated_minutes'] > 0 ? min(600, $mg['estimated_minutes']) : null);
                $have[] = mb_strtolower($desc);
                $room--;
            }
        }
        $tr = OutputValidator::ACTION_TO_TRANSITION[$data['proposed_action']] ?? null;
        if ($tr !== null) {
            $r = Repo::transition($sid, $tr, 'agent');
            $meta['transition'] = $r['ok'] ? 'applicata' : ('rifiutata:' . $r['error']);
            if ($r['ok'] && $tr === 'begin_performance') { self::activateNext($sid, 'system'); }
        }
        if ($data['proposed_action'] === 'request_self_monitoring') { Repo::recordEvent($sid, 'agent', 'agent_prompt_self_monitoring'); }
        if ($data['proposed_action'] === 'offer_hint') { Repo::recordEvent($sid, 'agent', 'agent_hint_offered'); }
        if (($trigger['type'] ?? '') === 'message' && SolutionRequest::matches((string)($trigger['text'] ?? ''))) {
            Repo::recordEvent($sid, 'system', 'solution_request_detected', ['message_id' => $trigger['message_id']]);
        }
        self::saveMessage($sid, 'agent', $data['student_message'], $c['phase'], ['simulated' => $res['simulated'], 'meta' => $meta]);
        return ['notice' => null];
    }
}
