<?php
declare(strict_types=1);

namespace Ablet\Service;

use Ablet\Domain\Analytics\Calibration;
use Ablet\Support\Database;

/**
 * Indicatori descrittivi (definizioni in resources/indicators). Classi di dato tenute distinte:
 * osservato (eventi), autovalutato, valutato dal professionista. Le inferenze dell'LLM non entrano in alcun indicatore.
 * I valori non calcolabili sono restituiti come null con il motivo, mai come zero.
 */
final class Indicators
{
    private static function all(string $sql, array $a): array
    {
        $st = Database::pdo()->prepare($sql);
        $st->execute($a);
        return $st->fetchAll();
    }

    public static function session(int $sid): array
    {
        $c = SessionView::context($sid);
        $min = static fn(?float $s) => $s === null ? null : round($s / 60, 1);
        $types = array_count_values(array_column($c['events'], 'type'));

        $open = array_values(array_filter($c['goals'], static fn($g) => $g['status'] !== 'dropped'));
        $done = count(array_filter($open, static fn($g) => $g['status'] === 'completed'));
        $items = array_map(static function (array $g): array {
            $rec = ($g['st'] && $g['en']) ? round(($g['en'] - $g['st']) / 60, 1) : null;
            $est = $g['estimated_minutes'] !== null ? (float)$g['estimated_minutes'] : null;
            return ['id' => (int)$g['id'], 'n' => (int)$g['position'], 'description' => $g['description'], 'status' => $g['status'], 'estimated_min' => $est, 'recorded_min' => $rec,
                'hints' => (int)$g['hints_used'], 'estimate_gap' => ($est && $rec !== null) ? round(($rec - $est) / $est, 2) : null];
        }, $open);

        $self = self::all('SELECT id, goal_id, kind, value_num, value_text, scale_min, scale_max, UNIX_TIMESTAMP(created_at) at FROM self_assessments WHERE session_id = ? ORDER BY id', [$sid]);
        $ratings = self::all("SELECT pr.goal_id, pr.score, pr.note, r.id rubric_id, r.title, r.purpose, r.scale_min, r.scale_max FROM professional_ratings pr JOIN rubrics r ON r.id = pr.rubric_id WHERE pr.session_id = ? ORDER BY pr.id", [$sid]);
        $perf = [];
        foreach ($ratings as $r) {
            if ($r['purpose'] === 'prestazione' && $r['score'] !== null) { $perf[$r['goal_id'] ?? 0] = $r; }
        }
        $pairs = [];
        foreach ($self as $s) {
            if ($s['kind'] !== 'sicurezza') { continue; }
            $p = $perf[$s['goal_id'] ?? 0] ?? null;
            $pairs[] = ['confidence' => (float)$s['value_num'], 'conf_min' => (float)$s['scale_min'], 'conf_max' => (float)$s['scale_max'],
                'performance' => $p ? (float)$p['score'] : null, 'perf_min' => $p ? (float)$p['scale_min'] : null, 'perf_max' => $p ? (float)$p['scale_max'] : null, 'rubric_id' => $p ? (int)$p['rubric_id'] : null];
        }

        $msgs = self::all("SELECT id, role, content, phase, modality, is_simulated, in_reply_to, llm_meta, UNIX_TIMESTAMP(created_at) at FROM messages WHERE session_id = ? AND role IN ('student','agent') ORDER BY id", [$sid]);
        $lat = []; $gaps = []; $sent = []; $prev = null; $sol = 0;
        foreach ($msgs as $m) {
            if ($prev) {
                $d = round($m['at'] - $prev['at'], 1);
                $gaps[] = ['from' => (int)$prev['id'], 'to' => (int)$m['id'], 's' => $d, 'kind' => $prev['role'] . '→' . $m['role']];
                if ($prev['role'] === 'agent' && $m['role'] === 'student') { $lat[] = $d; }
            }
            if ($m['role'] === 'student') {
                $s = Sentiment::score($m['content']);
                $sent[] = ['message_id' => (int)$m['id'], 'score' => $s['score'], 'matched' => $s['matched']];
                $sol += (int)SolutionRequest::matches($m['content']);
            }
            $prev = $m;
        }
        sort($lat);
        $ann = self::all("SELECT id, message_id, code_key, segment_text, srl_phase, rationale, uncertainty, elicitation, annotator_type, model_name, review_status, supersedes_id, codebook_version_id
            FROM text_annotations WHERE session_id = ? ORDER BY id", [$sid]);
        $counts = []; $elic = ['spontanea' => 0, 'sollecitata' => 0, 'non_determinabile' => 0];
        foreach ($ann as $a) { // valide = non sostituite, non rifiutate, non astensioni
            if (in_array($a['review_status'], ['corretta', 'rifiutata'], true) || $a['code_key'] === null) { continue; }
            $counts[$a['code_key']] = ($counts[$a['code_key']] ?? 0) + 1;
            $elic[$a['elicitation']]++;
        }
        $out = null;
        foreach ($self as $s) { if ($s['kind'] === 'studio_fuori_app_min') { $out = (float)$s['value_num']; } }
        $timePlan = \Ablet\Domain\Srl\StateMachine::timePlan($c);
        $est = $timePlan['estimated_min'];

        return [
            'session' => ['id' => (int)$c['id'], 'public_id' => $c['public_id'], 'student_id' => (int)$c['student_id'], 'activity_mode' => $c['activity_mode'], 'activity' => $c['activity_mode'] === 'free' ? ($c['srl']['task_statement'] ?? $c['a_title']) : $c['a_title'], 'activity_id' => (int)$c['activity_id'],
                'state' => $c['state'], 'created_at' => (float)$c['created_ts'], 'agent_enabled' => (bool)$c['agent_enabled'], 'is_simulation' => (bool)$c['is_simulation'],
                'protocol_version_id' => $c['protocol_version_id'], 'prompt_hash' => $c['prompt_hash'], 'support_level' => (int)$c['support_level'], 'step_size' => (int)$c['step_size'],
                'safety_flag' => isset($types['safety_signal']), 'slots' => $c['srl']],
            'time_plan' => $timePlan,
            'time' => ['estimated_min' => $est !== null ? (float)$est : null, 'elapsed_min' => $min($c['clock']['elapsed_s']), 'active_min' => $min($c['clock']['active_s']),
                'interaction_min' => $min($c['clock']['interaction_s']), 'paused_min' => $min($c['clock']['paused_s']), 'pause_count' => $c['clock']['pause_count'],
                'inactivity_gaps' => $c['clock']['inactivity_gaps'], 'start_latency_s' => $c['clock']['start_latency_s'], 'declared_outside_app_min' => $out],
            'goals' => ['agreed' => count($open), 'completed' => $done, 'pct' => count($open) ? round(100 * $done / count($open)) : null, 'items' => $items],
            'help' => ['hint_requests' => $types['hint_requested'] ?? 0, 'difficulty_flags' => $types['difficulty_flagged'] ?? 0, 'solution_requests' => $sol],
            'agent_prompts' => ($types['agent_prompt_self_monitoring'] ?? 0) + ($types['agent_hint_offered'] ?? 0),
            'strategies' => ['declared' => $c['srl']['strategy'] ?? null, 'changes' => $types['strategy_changed'] ?? 0],
            'phases' => self::all('SELECT action, from_state, to_state, actor, UNIX_TIMESTAMP(created_at) at FROM state_transitions WHERE session_id = ? ORDER BY id', [$sid]),
            'scaffolding' => self::all('SELECT id, source, rule_key, action, outcome, prev_support_level, new_support_level, prev_step_size, new_step_size, evidence_event_ids, indicators, UNIX_TIMESTAMP(created_at) at FROM adaptations WHERE session_id = ? ORDER BY id', [$sid]),
            'self_assessments' => $self,
            'ratings' => $ratings,
            'calibration' => Calibration::compute($pairs),
            'gaps' => ['items' => $gaps, 'student_response_median_s' => $lat ? $lat[intdiv(count($lat), 2)] : null, 'student_response_max_s' => $lat ? end($lat) : null, 'n' => count($lat)],
            'sentiment' => ['algorithm' => Sentiment::ALGORITHM, 'items' => $sent],
            'codes' => ['counts' => $counts, 'elicitation' => $elic],
            'usage' => self::all("SELECT COUNT(*) requests, SUM(status = 'ok') successful, SUM(status = 'cooldown') cooldowns, COALESCE(SUM(tokens_in),0) tokens_in, COALESCE(SUM(tokens_out),0) tokens_out, SUM(tokens_in IS NULL OR tokens_out IS NULL) unknown_usage FROM llm_calls WHERE session_id = ?", [$sid])[0],
            'llm_calls' => self::all('SELECT id, purpose, provider_key, model_name, status, http_status, tokens_in, tokens_out, duration_ms, created_at FROM llm_calls WHERE session_id = ? ORDER BY id DESC LIMIT 30', [$sid]),
            'system' => ['provider_errors' => $types['provider_error'] ?? 0, 'invalid_outputs' => $types['invalid_output'] ?? 0, 'blocked_outputs' => $types['output_blocked'] ?? 0],
            'messages' => array_map(static fn($m) => ['id' => (int)$m['id'], 'role' => $m['role'], 'content' => $m['content'], 'phase' => $m['phase'], 'modality' => $m['modality'],
                'simulated' => (bool)$m['is_simulated'], 'at' => (float)$m['at'], 'meta' => json_decode($m['llm_meta'] ?? 'null', true)], $msgs),
            'annotations' => $ann,
        ];
    }

    /** @param int[] $sessionIds */
    public static function overview(array $sessionIds, int $k = 3): array
    {
        $rows = []; $vectors = [];
        $codes = array_column(Annotator::codebook()['content']['codes'], 'key');
        $agg = ['started' => 0, 'completed' => 0, 'open' => 0, 'goals_agreed' => 0, 'goals_completed' => 0, 'hints' => 0, 'agent_prompts' => 0, 'safety' => 0];
        foreach ($sessionIds as $sid) {
            $m = self::session($sid);
            unset($m['messages'], $m['annotations'], $m['gaps']['items']);
            $rows[] = $m;
            $agg['started'] += (int)($m['time']['elapsed_min'] !== null);
            $agg['completed'] += (int)($m['session']['state'] === 'closed_complete');
            $agg['open'] += (int)!in_array($m['session']['state'], ['closed_complete', 'closed_incomplete'], true);
            $agg['goals_agreed'] += $m['goals']['agreed'];
            $agg['goals_completed'] += $m['goals']['completed'];
            $agg['hints'] += $m['help']['hint_requests'];
            $agg['agent_prompts'] += $m['agent_prompts'];
            $agg['safety'] += (int)$m['session']['safety_flag'];
            $tot = array_sum($m['codes']['counts']);
            if ($tot > 0) {
                $vectors[$sid] = array_map(static fn($c) => ($m['codes']['counts'][$c] ?? 0) / $tot, $codes);
            }
        }
        $agg['completion_pct'] = $agg['started'] ? round(100 * $agg['completed'] / $agg['started']) : null;
        $agg['goals_pct'] = $agg['goals_agreed'] ? round(100 * $agg['goals_completed'] / $agg['goals_agreed']) : null;
        return ['sessions' => $rows, 'aggregate' => $agg, 'codes' => $codes,
            'clusters' => count($vectors) >= 2 ? Clustering::kmeans($vectors, $k) + ['profiles' => $vectors] : ['k' => 0, 'assignment' => [], 'centroids' => [], 'profiles' => $vectors,
                'note' => 'Clustering non calcolabile: servono almeno due sessioni con annotazioni valide.']];
    }
}
