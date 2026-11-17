<?php
declare(strict_types=1);

namespace Ablet\Service;

use Ablet\Domain\Analytics\SessionClock;
use Ablet\Domain\Srl\StateMachine as SM;
use Ablet\Llm\Gateway;
use Ablet\Support\Database;

/** Viste della sessione: contesto interno, stato per l'agente, stato pubblico per lo studente. */
final class SessionView
{
    public const PROPOSALS = [
        'propose_decrease_support' => 'Stai andando avanti da solo, senza indizi. Vuoi provare con meno guida da parte mia?',
        'propose_increase_step_size' => 'Hai finito gli ultimi passi. Vuoi provare con passi un po\' più ampi?',
        'suggest_alternative_strategy' => 'Hai incontrato più volte un ostacolo con la stessa strategia. Vuoi confrontarla con un\'altra?',
    ];

    public static function context(int $sid): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare('SELECT s.*, UNIX_TIMESTAMP(s.created_at) AS created_ts, a.title AS a_title, a.assignment_text, a.completion_criteria, a.expected_minutes,
                a.agent_enabled, a.activity_mode, a.owner_id, pv.config_json, pv.compiled_prompt
            FROM sessions s JOIN activities a ON a.id = s.activity_id LEFT JOIN protocol_versions pv ON pv.id = s.protocol_version_id WHERE s.id = ?');
        $st->execute([$sid]);
        $row = $st->fetch();
        if (!$row) {
            throw new \DomainException('sessione_inesistente');
        }
        $row['cfg'] = $row['config_json'] ? json_decode($row['config_json'], true) : [];
        $row['srl'] = json_decode($row['srl_data'], true) ?: [];
        foreach (($row['srl']['_activity_snapshot'] ?? []) as $key=>$value) { if(in_array($key,['a_title','assignment_text','completion_criteria','expected_minutes'],true))$row[$key]=$value; }
        unset($row['srl']['_activity_snapshot']);
        $q = static function (string $sql) use ($pdo, $sid): array { $s = $pdo->prepare($sql); $s->execute([$sid]); return $s->fetchAll(); };
        $row['goals'] = $q('SELECT id, position, description, estimated_minutes, status, hints_used, UNIX_TIMESTAMP(started_at) st, UNIX_TIMESTAMP(ended_at) en FROM session_goals WHERE session_id = ? ORDER BY position');
        $m = $pdo->prepare('SELECT id, title, content_text FROM materials WHERE activity_id = ? ORDER BY id');
        $m->execute([$row['activity_id']]);
        $row['materials'] = $m->fetchAll();
        $row['events'] = $q('SELECT id, type, actor, UNIX_TIMESTAMP(server_ts) ts FROM events WHERE session_id = ? ORDER BY id');
        $row['clock'] = SessionClock::compute($row['events'], (float)$row['created_ts'], microtime(true));
        $row['phase'] = in_array($row['state'], SM::PHASES, true) ? $row['state'] : ($row['resume_state'] ?? 'forethought');
        return $row;
    }

    /** Elementi ancora da raccogliere prima della performance, in ordine di richiesta. */
    public static function nextMissing(array $c): array
    {
        $f = $c['cfg']['srl_procedures']['forethought'] ?? [];
        $order = ['task_statement'];
        if (!empty($f['require_restatement'])) { $order[] = 'restatement'; }
        foreach (['expected_outcome', 'completion_criterion', 'prior_knowledge', 'expected_difficulty', 'self_efficacy', 'strategy'] as $s) {
            if (in_array($s, $f['required_slots'] ?? [], true)) { $order[] = $s; }
        }
        $missing = array_values(array_filter($order, static fn($s) => !isset($c['srl'][$s]) || $c['srl'][$s] === ''));
        $open = count(array_filter($c['goals'], static fn($g) => $g['status'] !== 'dropped'));
        for ($i = $open; $i < max(1, (int)($f['min_micro_goals'] ?? 1)); $i++) { $missing[] = 'micro_goal'; }
        if (in_array('time_estimate_min', $f['required_slots'] ?? [], true) && !isset($c['srl']['time_estimate_min'])) { $missing[] = 'time_estimate_min'; }
        if (($c['activity_mode'] ?? 'prepared') === 'free') {
            $missing = array_values(array_diff($missing, ['time_estimate_min']));
            if (SM::timePlan($c)['estimated_min'] === null) { $missing[] = 'time_estimate_min'; }
        }
        return $missing;
    }

    public static function currentGoal(array $c): ?array
    {
        foreach ($c['goals'] as $g) {
            if ($g['status'] === 'active') { return $g; }
        }
        return null;
    }

    public static function checkpointDue(array $c): bool
    {
        $every = (int)($c['cfg']['timing']['checkpoint_every_steps'] ?? 0);
        $level = (int)$c['support_level'];
        if ($every <= 0 || $level === 0 || $c['phase'] !== 'performance') {
            return false; // livello 0: solo checkpoint essenziali (avvio, chiusura)
        }
        $every *= $level === 1 ? 2 : 1;
        $closed = 0;
        foreach ($c['events'] as $e) {
            if ($e['type'] === 'agent_prompt_self_monitoring') { $closed = 0; }
            elseif (in_array($e['type'], ['step_completed', 'step_not_completed'], true)) { $closed++; }
        }
        return $closed >= $every;
    }

    public static function forAgent(array $c, array $trigger): array
    {
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT content, phase FROM messages WHERE session_id = ? AND role = 'student'");
        $st->execute([$c['id']]);
        $sol = 0; $refl = 0;
        foreach ($st->fetchAll() as $m) {
            $sol += (int)SolutionRequest::matches($m['content']);
            $refl += (int)($m['phase'] === 'self_reflection');
        }
        $timePlan = SM::timePlan($c);
        $est = $timePlan['estimated_min'];
        $levels = $c['cfg']['scaffolding']['levels'] ?? [];
        $goal = self::currentGoal($c);
        $pending = null;
        if ($c['pending_adaptation_id']) {
            $p = $pdo->prepare('SELECT action FROM adaptations WHERE id = ?');
            $p->execute([$c['pending_adaptation_id']]);
            $pending = self::PROPOSALS[(string)$p->fetchColumn()] ?? null;
        }
        $g = static fn(array $x) => ['n' => (int)$x['position'], 'description' => $x['description'], 'estimated_minutes' => $x['estimated_minutes'],
            'status' => $x['status'], 'hints_used' => (int)$x['hints_used'],
            'recorded_minutes' => ($x['st'] && $x['en']) ? round(($x['en'] - $x['st']) / 60, 1) : null];
        return [
            'phase' => $c['phase'],
            'activity_mode' => $c['activity_mode'],
            'assignment' => $c['activity_mode'] === 'free' ? ($c['srl']['task_statement'] ?? null) : $c['assignment_text'],
            'completion_criteria' => $c['activity_mode'] === 'free' ? ($c['srl']['completion_criterion'] ?? null) : $c['completion_criteria'],
            'professional_activity_guidance' => $c['activity_mode'] === 'free' ? ['instructions'=>$c['assignment_text'],'completion_guidance'=>$c['completion_criteria'],'indicative_minutes'=>$c['expected_minutes'],'note'=>'Indicazioni generali del professionista: il compito e la stima del tempo sono definiti dallo studente nel dialogo SRL.'] : null,
            'content_source' => $c['activity_mode'] === 'free' ? 'model_knowledge_unverified' : 'professional_materials',
            'support_level' => (int)$c['support_level'], 'support_description' => $levels['livello_' . $c['support_level']] ?? null,
            'step_size' => (int)$c['step_size'], 'step_size_note' => 'da 1 (passi minimi) a 5 (passi ampi); 3 è il valore iniziale',
            'slots' => $c['srl'], 'goals' => array_map($g, $c['goals']), 'current_step' => $goal ? $g($goal) : null,
            'next_missing' => $c['phase'] === 'forethought' ? self::nextMissing($c) : [],
            'max_micro_goals' => (int)($c['cfg']['srl_procedures']['forethought']['max_micro_goals'] ?? 5),
            'checkpoint_due' => self::checkpointDue($c), 'pending_proposal_to_student' => $pending,
            'timing' => $timePlan + ['estimated_min' => $est, 'active_min' => $c['clock']['active_s'] !== null ? (int)round($c['clock']['active_s'] / 60) : null],
            'reflection_turns' => $refl, 'solution_requests' => $sol, 'trigger' => $trigger,
        ];
    }

    public static function forStudent(int $sid): array
    {
        $c = self::context($sid);
        $pdo = Database::pdo();
        $st = $pdo->prepare("SELECT id, role, content, is_simulated, UNIX_TIMESTAMP(created_at) at FROM messages WHERE session_id = ? AND role IN ('student','agent') ORDER BY id");
        $st->execute([$sid]);
        $proposal = null;
        if ($c['pending_adaptation_id']) {
            $p = $pdo->prepare('SELECT action FROM adaptations WHERE id = ?');
            $p->execute([$c['pending_adaptation_id']]);
            $proposal = ['id' => (int)$c['pending_adaptation_id'], 'text' => self::PROPOSALS[(string)$p->fetchColumn()] ?? 'Proposta di modifica del supporto.'];
        }
        $prov = Gateway::configFor((int)$c['owner_id'], $c['cfg']['provider']['provider_config_id'] ?? null);
        $set = $pdo->prepare('SELECT voice, avatar FROM professional_settings WHERE owner_id = ?');
        $set->execute([$c['owner_id']]);
        $settings = $set->fetch() ?: ['voice' => null, 'avatar' => null];
        $mod = $c['cfg']['modality'] ?? [];
        $voice = (json_decode($settings['voice'] ?? 'null', true) ?: []) + ['engine' => 'browser', 'language' => 'it-IT', 'rate' => 1.0, 'pitch' => 1.0];
        $voiceOk = Consent::missing((int)$c['student_id'], (int)$c['owner_id'], ['voce']) === [];
        $voice['input_enabled'] = $voiceOk && ($mod['voice_input'] ?? true) && ($voice['input_enabled'] ?? true);
        $voice['output_enabled'] = $voiceOk && ($mod['voice_output'] ?? true) && ($voice['output_enabled'] ?? true);
        if (($voice['engine'] ?? 'browser') === 'server' && !in_array('audio_provider', Consent::active((int)$c['student_id']), true)) {
            $voiceOk = false; $voice['input_enabled'] = false; $voice['output_enabled'] = false;
        }
        $voice['api_context'] = ['session_public_id' => $c['public_id']];
        if (($voice['engine'] ?? 'browser') === 'server' && !empty($voice['provider_config_id'])) {
            $audioProvider = Gateway::configFor((int)$c['owner_id'], (int)$voice['provider_config_id']);
            $voice['audio_service'] = $audioProvider['label'];
        }
        $voice['consent_missing'] = !$voiceOk;
        $goal = self::currentGoal($c);
        return [
            'id' => $c['public_id'], 'state' => $c['state'], 'phase' => $c['phase'], 'state_version' => (int)$c['state_version'],
            'agent_enabled' => (bool)$c['agent_enabled'] && $c['protocol_version_id'] !== null,
            'demo' => $prov['provider_key'] === 'demo',
            'text_service' => $prov['provider_key'] === 'demo' ? 'Nessun servizio esterno: risposte simulate dalla piattaforma' : "{$prov['label']} ({$prov['provider_key']})",
            'time_plan' => SM::timePlan($c),
            'activity' => ['mode' => $c['activity_mode'], 'title' => $c['activity_mode'] === 'free' ? ($c['srl']['task_statement'] ?? $c['a_title']) : $c['a_title'], 'assignment' => $c['activity_mode'] === 'free' ? ($c['srl']['task_statement'] ?? $c['assignment_text']) : $c['assignment_text'], 'criteria' => $c['activity_mode'] === 'free' ? ($c['srl']['completion_criterion'] ?? null) : $c['completion_criteria'], 'expected_minutes' => $c['expected_minutes']],
            'materials' => array_map(static fn($m) => ['id' => (int)$m['id'], 'title' => $m['title'], 'content' => $m['content_text']], $c['materials']),
            'slots' => $c['srl'],
            'goals' => array_map(static fn($g) => ['id' => (int)$g['id'], 'n' => (int)$g['position'], 'description' => $g['description'], 'minutes' => $g['estimated_minutes'], 'status' => $g['status']], $c['goals']),
            'current_goal_id' => $goal ? (int)$goal['id'] : null,
            'missing' => $c['phase'] === 'forethought' ? self::nextMissing($c) : [],
            'max_goals' => (int)($c['cfg']['srl_procedures']['forethought']['max_micro_goals'] ?? 8),
            'support_level' => (int)$c['support_level'],
            'messages' => array_map(static fn($m) => ['id' => (int)$m['id'], 'role' => $m['role'], 'content' => $m['content'], 'simulated' => (bool)$m['is_simulated'], 'at' => (float)$m['at']], $st->fetchAll()),
            'clock' => ['active_s' => $c['clock']['active_s'], 'running' => in_array($c['state'], SM::PHASES, true)],
            'proposal' => $proposal,
            'assessed' => (static function () use ($pdo, $sid): array { $q = $pdo->prepare('SELECT DISTINCT kind FROM self_assessments WHERE session_id = ?'); $q->execute([$sid]); return $q->fetchAll(\PDO::FETCH_COLUMN); })(),
            'voice' => $voice, 'avatar' => (json_decode($settings['avatar'] ?? 'null', true) ?: []) + ['enabled' => false, 'name' => 'Ablet', 'style' => 'astratto', 'color' => '#2f6f6a'],
        ];
    }
}
