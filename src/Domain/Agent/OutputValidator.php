<?php
declare(strict_types=1);

namespace Ablet\Domain\Agent;

use Ablet\Domain\Srl\StateMachine;

/**
 * Convalida l'output strutturato dell'LLM prima di qualsiasi effetto sullo stato.
 * Lo schema corrispondente per i provider è in resources/schema/agent_output.schema.json.
 */
final class OutputValidator
{
    public const ACTIONS = [
        'continue', 'offer_hint', 'suggest_strategy_change', 'request_self_monitoring',
        'begin_performance', 'return_to_planning', 'begin_reflection', 'suggest_close', 'safety_hold',
    ];
    /** Azioni proposte che corrispondono a una transizione della macchina a stati. */
    public const ACTION_TO_TRANSITION = [
        'begin_performance' => 'begin_performance',
        'return_to_planning' => 'return_to_planning',
        'begin_reflection' => 'begin_reflection',
        'safety_hold' => 'safety_hold',
        // 'suggest_close' NON chiude: la chiusura richiede la conferma dello studente.
    ];
    public const SAFETY = ['ok', 'distress', 'emergency'];
    public const MAX_MESSAGE_CHARS = 1200;

    private const REQUIRED = [
        'student_message', 'current_phase', 'proposed_action', 'proposed_state_update',
        'evidence_message_ids', 'suggested_support_level', 'safety_status',
    ];
    private const UPDATE_KEYS = ['slots', 'micro_goals'];

    /**
     * @param string $raw testo restituito dal provider
     * @param int[] $studentMessageIds id dei messaggi dello studente appartenenti a QUESTA sessione
     * @return array{ok:bool,errors:string[],data?:array<string,mixed>}
     */
    /** Toglie un eventuale blocco ```json … ``` o testo attorno all'oggetto: alcuni modelli lo aggiungono anche in modalità JSON. */
    public static function unwrap(string $raw): string
    {
        $t = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $t, $m)) { $t = $m[1]; }
        $a = strpos($t, '{'); $b = strrpos($t, '}');
        return ($a !== false && $b !== false && $b > $a) ? substr($t, $a, $b - $a + 1) : $t;
    }

    public static function validate(string $raw, array $studentMessageIds): array
    {
        $raw = self::unwrap($raw);
        try {
            $d = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'errors' => ['json_non_valido']];
        }
        if (!is_array($d) || array_is_list($d)) {
            return ['ok' => false, 'errors' => ['oggetto_atteso']];
        }
        $e = [];
        foreach (self::REQUIRED as $k) {
            if (!array_key_exists($k, $d)) {
                $e[] = "campo_mancante:$k";
            }
        }
        if ($extra = array_diff(array_keys($d), self::REQUIRED)) {
            $e[] = 'campi_non_previsti:' . implode(',', $extra);
        }
        if ($e !== []) {
            return ['ok' => false, 'errors' => $e];
        }

        if (!is_string($d['student_message']) || trim($d['student_message']) === '') {
            $e[] = 'student_message_vuoto';
        } elseif (mb_strlen($d['student_message']) > self::MAX_MESSAGE_CHARS) {
            $e[] = 'student_message_troppo_lungo';
        }
        if (!in_array($d['current_phase'], array_merge(StateMachine::PHASES, [StateMachine::SAFETY_HOLD]), true)) {
            $e[] = 'current_phase_non_valida';
        }
        if (!in_array($d['proposed_action'], self::ACTIONS, true)) {
            $e[] = 'proposed_action_non_valida';
        }
        if (!in_array($d['safety_status'], self::SAFETY, true)) {
            $e[] = 'safety_status_non_valido';
        }
        if (!is_int($d['suggested_support_level']) || $d['suggested_support_level'] < 0 || $d['suggested_support_level'] > 3) {
            $e[] = 'suggested_support_level_non_valido';
        }

        $ids = $d['evidence_message_ids'];
        if (!is_array($ids) || !array_is_list($ids) || array_filter($ids, static fn($x) => !is_int($x))) {
            $e[] = 'evidence_message_ids_non_validi';
        } elseif (array_diff($ids, $studentMessageIds)) {
            // Riferimenti a messaggi inesistenti, dell'agente o di altre sessioni.
            $e[] = 'evidence_message_ids_estranei_alla_sessione';
        }

        $u = $d['proposed_state_update'];
        if (!is_array($u) || ($u !== [] && array_is_list($u))) {
            $e[] = 'proposed_state_update_non_valido';
        } else {
            if (array_diff(array_keys($u), self::UPDATE_KEYS)) {
                $e[] = 'proposed_state_update_chiavi_non_ammesse';
            }
            foreach (array_keys($u['slots'] ?? []) as $slot) {
                if (!in_array($slot, StateMachine::FORETHOUGHT_SLOTS, true)) {
                    $e[] = "slot_non_ammesso:$slot";
                }
            }
            foreach (['expected_difficulty', 'self_efficacy'] as $scale) {
                if (isset($u['slots'][$scale]) && (!is_int($u['slots'][$scale]) || $u['slots'][$scale] < 1 || $u['slots'][$scale] > 5)) {
                    $e[] = "scala_1_5_non_valida:$scale";
                }
            }
            foreach (['time_estimate_min', 'available_time_min'] as $time) {
                if (isset($u['slots'][$time]) && (!is_int($u['slots'][$time]) || $u['slots'][$time] < 1 || $u['slots'][$time] > 600)) {
                    $e[] = "tempo_non_valido:$time";
                }
            }
            foreach ($u['micro_goals'] ?? [] as $g) {
                if (!is_array($g) || !is_string($g['description'] ?? null) || trim($g['description']) === '') {
                    $e[] = 'micro_goal_non_valido';
                    break;
                }
                if (isset($g['estimated_minutes']) && (!is_int($g['estimated_minutes']) || $g['estimated_minutes'] < 1 || $g['estimated_minutes'] > 600)) {
                    $e[] = 'tempo_micro_goal_non_valido';
                }
            }
            // Ogni aggiornamento dello stato deve poggiare su almeno un messaggio dello studente:
            // l'agente non può attribuire allo studente obiettivi o valutazioni non espressi.
            if (is_array($u) && $u !== [] && is_array($ids) && $ids === []) {
                $e[] = 'aggiornamento_senza_evidenza';
            }
        }
        if ($d['safety_status'] !== 'ok' && $d['proposed_action'] !== 'safety_hold') {
            $e[] = 'segnale_di_sicurezza_senza_sospensione';
        }

        return $e === [] ? ['ok' => true, 'errors' => [], 'data' => $d] : ['ok' => false, 'errors' => $e];
    }

    /** Unica parte dell'output che può raggiungere il frontend. */
    public static function publicView(array $validated, string $serverPhase): array
    {
        return ['message' => $validated['student_message'], 'phase' => $serverPhase];
    }

    /** Messaggio di ripiego quando l'output è invalido o il provider non è disponibile. Non è generato dal modello. */
    public static function fallbackMessage(): string
    {
        return 'Scusa, adesso non riesco a risponderti bene. Il tuo lavoro è salvato. '
            . 'Puoi continuare con il passo che stai facendo, oppure riprovare tra poco.';
    }
}
