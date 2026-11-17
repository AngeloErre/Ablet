<?php
declare(strict_types=1);

namespace Ablet\Domain\Srl;

/**
 * Macchina a stati del ciclo SRL (Zimmerman, 2000): forethought → performance → self-reflection,
 * con ritorni alla pianificazione, pause, riprese, chiusure incomplete e sospensione di sicurezza.
 *
 * Funzione pura: riceve lo stato e restituisce il nuovo stato o un errore. La persistenza è nel repository.
 * L'LLM (attore "agent") può soltanto PROPORRE alcune transizioni; la validità è decisa qui.
 */
final class StateMachine
{
    public const CREATED = 'created';
    public const FORETHOUGHT = 'forethought';
    public const PERFORMANCE = 'performance';
    public const SELF_REFLECTION = 'self_reflection';
    public const PAUSED = 'paused';
    public const SAFETY_HOLD = 'safety_hold';
    public const CLOSED_COMPLETE = 'closed_complete';
    public const CLOSED_INCOMPLETE = 'closed_incomplete';

    public const PHASES = [self::FORETHOUGHT, self::PERFORMANCE, self::SELF_REFLECTION];
    public const ACTORS = ['student', 'agent', 'professional', 'system'];

    /** Slot della fase di forethought. Tutti dichiarati dallo studente. */
    public const FORETHOUGHT_SLOTS = [
        'task_statement', 'expected_outcome', 'completion_criterion', 'prior_knowledge',
        'expected_difficulty', 'self_efficacy', 'strategy', 'time_estimate_min', 'available_time_min', 'restatement',
    ];

    /** Requisito minimo di piattaforma per iniziare a lavorare: sapere che cosa si deve fare. */
    private const PLATFORM_REQUIRED_SLOTS = ['task_statement'];

    /**
     * action => [from[], to, actors[], guard?]
     * to = '@resume' significa "torna alla fase memorizzata in resume_state".
     */
    private const TRANSITIONS = [
        'start'              => ['from' => [self::CREATED], 'to' => self::FORETHOUGHT, 'actors' => ['student', 'system']],
        'begin_performance'  => ['from' => [self::FORETHOUGHT], 'to' => self::PERFORMANCE, 'actors' => ['student', 'agent'], 'guard' => 'forethoughtReady'],
        'return_to_planning' => ['from' => [self::PERFORMANCE, self::SELF_REFLECTION], 'to' => self::FORETHOUGHT, 'actors' => ['student', 'agent']],
        'begin_reflection'   => ['from' => [self::PERFORMANCE], 'to' => self::SELF_REFLECTION, 'actors' => ['student', 'agent']],
        'pause'              => ['from' => self::PHASES, 'to' => self::PAUSED, 'actors' => ['student', 'system']],
        'resume'             => ['from' => [self::PAUSED], 'to' => '@resume', 'actors' => ['student']],
        // La chiusura completa richiede di essere passati dalla riflessione e la conferma dello studente.
        'close_complete'     => ['from' => [self::SELF_REFLECTION], 'to' => self::CLOSED_COMPLETE, 'actors' => ['student']],
        'close_incomplete'   => ['from' => [self::FORETHOUGHT, self::PERFORMANCE, self::SELF_REFLECTION, self::PAUSED, self::SAFETY_HOLD], 'to' => self::CLOSED_INCOMPLETE, 'actors' => ['student', 'professional', 'system']],
        'safety_hold'        => ['from' => [self::FORETHOUGHT, self::PERFORMANCE, self::SELF_REFLECTION, self::PAUSED], 'to' => self::SAFETY_HOLD, 'actors' => ['agent', 'system', 'professional']],
        'safety_release'     => ['from' => [self::SAFETY_HOLD], 'to' => '@resume', 'actors' => ['student', 'professional']],
    ];

    /** @return string[] */
    public static function actions(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function isClosed(string $state): bool
    {
        return in_array($state, [self::CLOSED_COMPLETE, self::CLOSED_INCOMPLETE], true);
    }

    /**
     * @param array{state:string,resume_state:?string,srl:array<string,mixed>,goals:array<int,array<string,mixed>>} $s
     * @param array{required_slots?:string[],require_restatement?:bool,min_micro_goals?:int} $protocol
     * @return array{ok:bool,state?:array<string,mixed>,error?:string,detail?:mixed}
     */
    public static function apply(array $s, string $action, string $actor, array $protocol = []): array
    {
        if (!in_array($actor, self::ACTORS, true)) {
            return ['ok' => false, 'error' => 'attore_sconosciuto'];
        }
        $t = self::TRANSITIONS[$action] ?? null;
        if ($t === null) {
            return ['ok' => false, 'error' => 'azione_sconosciuta'];
        }
        if (!in_array($s['state'], $t['from'], true)) {
            return ['ok' => false, 'error' => 'transizione_non_valida', 'detail' => ['from' => $s['state'], 'action' => $action]];
        }
        if (!in_array($actor, $t['actors'], true)) {
            return ['ok' => false, 'error' => 'attore_non_autorizzato', 'detail' => ['actor' => $actor, 'action' => $action]];
        }
        if (($t['guard'] ?? null) === 'forethoughtReady') {
            $missing = self::forethoughtMissing($s, $protocol);
            if ($missing !== []) {
                return ['ok' => false, 'error' => 'pianificazione_incompleta', 'detail' => $missing];
            }
        }

        $new = $s;
        $to = $t['to'];
        if ($to === '@resume') {
            $to = $s['resume_state'] ?? self::FORETHOUGHT;
            if (!in_array($to, self::PHASES, true)) {
                return ['ok' => false, 'error' => 'stato_di_ripresa_non_valido'];
            }
            $new['resume_state'] = null;
        } elseif ($to === self::PAUSED || $to === self::SAFETY_HOLD) {
            // Sospensione di sicurezza richiesta durante una pausa: si conserva la fase originaria.
            $new['resume_state'] = in_array($s['state'], self::PHASES, true) ? $s['state'] : ($s['resume_state'] ?? self::FORETHOUGHT);
        }
        $new['state'] = $to;
        return ['ok' => true, 'state' => $new];
    }

    /**
     * Elementi mancanti per passare alla fase di performance.
     * Gli slot facoltativi possono essere saltati: il ciclo non è una sequenza rigida di domande.
     * @return string[]
     */
    public static function forethoughtMissing(array $s, array $protocol = []): array
    {
        $free = ($s['activity_mode'] ?? 'prepared') === 'free';
        $required = array_unique(array_merge(self::PLATFORM_REQUIRED_SLOTS, $protocol['required_slots'] ?? []));
        if ($free) { $required[] = 'time_estimate_min'; }
        if (!empty($protocol['require_restatement'])) {
            $required[] = 'restatement';
        }
        $missing = [];
        foreach ($required as $slot) {
            if ($free && $slot === 'time_estimate_min' && self::timePlan($s)['estimated_min'] !== null) { continue; }
            $v = $s['srl'][$slot] ?? null;
            if ($free && $slot === 'time_estimate_min') { $v = null; }
            if ($v === null || (is_string($v) && trim($v) === '')) {
                $missing[] = $slot;
            }
        }
        $min = max(1, (int)($protocol['min_micro_goals'] ?? 1));
        $open = array_filter($s['goals'] ?? [], static fn(array $g) => !in_array($g['status'] ?? 'pending', ['dropped'], true));
        if (count($open) < $min) {
            $missing[] = 'micro_goals(min ' . $min . ')';
        }
        return array_values(array_unique($missing));
    }
    /** Stime dichiarate e somma derivata rimangono separate; passi rimossi esclusi. */
    public static function timePlan(array $s): array
    {
        $valid = static fn($v): bool => filter_var($v, FILTER_VALIDATE_INT) !== false && (int)$v > 0 && (int)$v <= 600;
        $total = $s['srl']['time_estimate_min'] ?? null;
        $total = $valid($total) ? (int)$total : null;
        $available = $s['srl']['available_time_min'] ?? null;
        $available = $valid($available) ? (int)$available : null;
        $goals = array_values(array_filter($s['goals'] ?? [], static fn($g) => ($g['status'] ?? 'pending') !== 'dropped'));
        $complete = $goals !== [] && count(array_filter($goals, static fn($g) => $valid($g['estimated_minutes'] ?? null))) === count($goals);
        $sum = $complete ? array_sum(array_map(static fn($g) => (int)$g['estimated_minutes'], $goals)) : null;
        $est = $total ?? $sum;
        return ['declared_total_min' => $total, 'micro_total_min' => $sum, 'estimated_min' => $est,
            'estimate_source' => $total !== null ? 'student_total' : ($sum !== null ? 'sum_student_micro_goals' : null),
            'available_min' => $available, 'total_micro_gap_min' => $total !== null && $sum !== null ? $sum - $total : null,
            'exceeds_available' => $est !== null && $available !== null ? $est > $available : null];
    }

}
