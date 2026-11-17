<?php
declare(strict_types=1);

namespace Ablet\Domain\Adaptation;

/**
 * Motore di adattamento a regole esplicite.
 *
 *   eventi osservati ──► indicatori ──► regole (soglie del professionista) ──► decisione registrabile
 *
 * Gli indicatori derivano SOLO da eventi osservati (pulsanti, completamenti, richieste di indizio).
 * Autovalutazioni, inferenze dell'LLM e valutazioni professionali non entrano nel calcolo.
 *
 * Livelli di supporto (parametri progettuali da validare, non valori clinici):
 *   3 = guida strutturata · 2 = guida condivisa · 1 = supporto su richiesta · 0 = pianificazione autonoma con checkpoint essenziali
 * Ampiezza del passo: 1 (minima) … 5 (massima), 3 predefinita.
 */
final class RuleEngine
{
    public const LEVEL_MIN = 0;
    public const LEVEL_MAX = 3;
    public const STEP_MIN = 1;
    public const STEP_MAX = 5;

    /** Indicatori calcolati dal motore e utilizzabili nelle regole. */
    public const INDICATORS = [
        'consecutive_steps_not_completed', 'consecutive_steps_completed', 'consecutive_completed_without_hint',
        'hint_requests_current_step', 'hint_requests_total', 'difficulty_flags_current_step',
        'failed_attempts_current_strategy', 'steps_completed_total', 'steps_closed_total',
        // Ammessi solo insieme ad almeno un altro indicatore (mai come unica base):
        'response_latency_s', 'word_count',
    ];

    /** Non possono costituire da soli la condizione di una regola. */
    public const NOT_SUFFICIENT_ALONE = ['response_latency_s', 'word_count'];

    /** Mai ammessi come indicatori. */
    public const FORBIDDEN = ['diagnosis', 'diagnosi', 'voice_features', 'linguistic_style'];

    public const ACTIONS = [
        'reduce_step_size', 'propose_increase_step_size', 'increase_support',
        'propose_decrease_support', 'restore_support', 'suggest_alternative_strategy',
    ];

    private const OPS = ['>=', '>', '<=', '<', '=='];

    /**
     * Convalida l'insieme di regole definito dal professionista.
     * @param array<int,array<string,mixed>> $rules
     * @return string[] errori (vuoto = valido)
     */
    public static function validateRules(array $rules): array
    {
        $errors = [];
        $keys = [];
        foreach ($rules as $i => $r) {
            $k = (string)($r['key'] ?? '');
            $where = $k !== '' ? "regola '$k'" : "regola #$i";
            if ($k === '' || isset($keys[$k])) {
                $errors[] = "$where: chiave mancante o duplicata";
            }
            $keys[$k] = true;
            $conds = $r['when']['all'] ?? [];
            if (!is_array($conds) || $conds === []) {
                $errors[] = "$where: nessuna condizione osservabile";
                continue;
            }
            $sufficient = false;
            foreach ($conds as $c) {
                $ind = (string)($c['indicator'] ?? '');
                if (in_array($ind, self::FORBIDDEN, true)) {
                    $errors[] = "$where: l'indicatore '$ind' non è ammesso";
                } elseif (!in_array($ind, self::INDICATORS, true)) {
                    $errors[] = "$where: indicatore sconosciuto '$ind'";
                } elseif (!in_array($ind, self::NOT_SUFFICIENT_ALONE, true)) {
                    $sufficient = true;
                }
                if (!in_array($c['op'] ?? '', self::OPS, true) || !is_numeric($c['value'] ?? null)) {
                    $errors[] = "$where: operatore o soglia non validi";
                }
            }
            if (!$sufficient) {
                $errors[] = "$where: tempo di risposta e numero di parole non possono essere l'unica base di un adattamento";
            }
            if (!in_array($r['then']['action'] ?? '', self::ACTIONS, true)) {
                $errors[] = "$where: azione sconosciuta";
            }
        }
        return $errors;
    }

    /**
     * Calcola gli indicatori dagli eventi osservati della sessione (ordinati per id crescente).
     * Tipi rilevanti: step_started, step_completed, step_not_completed, hint_requested,
     * difficulty_flagged, attempt_failed, strategy_changed.
     *
     * @param array<int,array{id:int,type:string,payload?:array<string,mixed>}> $events
     * @return array{values:array<string,int>,evidence:array<string,int[]>}
     */
    public static function indicators(array $events): array
    {
        $v = array_fill_keys(array_diff(self::INDICATORS, self::NOT_SUFFICIENT_ALONE), 0);
        $ev = array_fill_keys(array_keys($v), []);
        $hintsThisStep = 0;

        foreach ($events as $e) {
            $id = (int)$e['id'];
            switch ($e['type']) {
                case 'step_started':
                    $v['hint_requests_current_step'] = 0; $ev['hint_requests_current_step'] = [];
                    $v['difficulty_flags_current_step'] = 0; $ev['difficulty_flags_current_step'] = [];
                    $hintsThisStep = 0;
                    break;
                case 'hint_requested':
                    $hintsThisStep++;
                    $v['hint_requests_current_step']++; $ev['hint_requests_current_step'][] = $id;
                    $v['hint_requests_total']++; $ev['hint_requests_total'][] = $id;
                    break;
                case 'difficulty_flagged':
                    $v['difficulty_flags_current_step']++; $ev['difficulty_flags_current_step'][] = $id;
                    break;
                case 'attempt_failed':
                    $v['failed_attempts_current_strategy']++; $ev['failed_attempts_current_strategy'][] = $id;
                    break;
                case 'strategy_changed':
                    $v['failed_attempts_current_strategy'] = 0; $ev['failed_attempts_current_strategy'] = [];
                    break;
                case 'step_completed':
                    $v['steps_completed_total']++; $v['steps_closed_total']++;
                    $v['consecutive_steps_completed']++; $ev['consecutive_steps_completed'][] = $id;
                    $v['consecutive_steps_not_completed'] = 0; $ev['consecutive_steps_not_completed'] = [];
                    if ($hintsThisStep === 0) {
                        $v['consecutive_completed_without_hint']++; $ev['consecutive_completed_without_hint'][] = $id;
                    } else {
                        $v['consecutive_completed_without_hint'] = 0; $ev['consecutive_completed_without_hint'] = [];
                    }
                    $hintsThisStep = 0;
                    break;
                case 'step_not_completed':
                    $v['steps_closed_total']++;
                    $v['consecutive_steps_not_completed']++; $ev['consecutive_steps_not_completed'][] = $id;
                    $v['consecutive_steps_completed'] = 0; $ev['consecutive_steps_completed'] = [];
                    $v['consecutive_completed_without_hint'] = 0; $ev['consecutive_completed_without_hint'] = [];
                    $hintsThisStep = 0;
                    break;
            }
        }
        return ['values' => $v, 'evidence' => $ev];
    }

    /**
     * Valuta le regole e restituisce al più UNA decisione (nessun cambiamento multiplo o brusco).
     *
     * @param array<int,array<string,mixed>> $rules  in ordine di priorità
     * @param array{values:array<string,int>,evidence:array<string,int[]>} $ind
     * @param array{support_level:int,step_size:int,preferences?:array<string,mixed>} $model
     * @param array<int,array<string,mixed>> $history adattamenti precedenti della sessione (ordine cronologico)
     * @return array<string,mixed>|null
     */
    public static function evaluate(array $rules, array $ind, array $model, array $history = []): ?array
    {
        $last = $history !== [] ? $history[count($history) - 1] : null;
        $lastMaxEvidence = $last ? max(array_merge([0], $last['evidence_event_ids'] ?? [])) : 0;

        foreach ($rules as $rule) {
            $evidence = [];
            $matched = true;
            foreach ($rule['when']['all'] as $c) {
                $val = $ind['values'][$c['indicator']] ?? null;
                if ($val === null || !self::compare((float)$val, $c['op'], (float)$c['value'])) {
                    $matched = false;
                    break;
                }
                $evidence = array_merge($evidence, $ind['evidence'][$c['indicator']] ?? []);
            }
            if (!$matched) {
                continue;
            }
            $evidence = array_values(array_unique($evidence));
            sort($evidence);

            // Anti-ripetizione: servono evidenze nuove rispetto all'ultimo adattamento,
            // e almeno `min_new_events` eventi probatori successivi ad esso.
            $new = array_filter($evidence, static fn(int $id) => $id > $lastMaxEvidence);
            $minNew = max(1, (int)($rule['min_new_events'] ?? 1));
            if ($last !== null && count($new) < $minNew) {
                continue;
            }
            // Il ripristino ha senso solo dopo una riduzione del supporto effettivamente applicata.
            $action = $rule['then']['action'];
            if ($action === 'restore_support') {
                $lastFade = self::lastApplied($history, 'propose_decrease_support');
                if ($lastFade === null) {
                    continue;
                }
            }

            $prevL = (int)$model['support_level'];
            $prevS = (int)$model['step_size'];
            [$newL, $newS, $outcome] = [$prevL, $prevS, 'applicata'];

            switch ($action) {
                case 'reduce_step_size':            $newS = max(self::STEP_MIN, $prevS - 1); break;
                case 'propose_increase_step_size':  $newS = min(self::STEP_MAX, $prevS + 1); $outcome = 'proposta'; break;
                case 'increase_support':            $newL = min(self::LEVEL_MAX, $prevL + 1); break;
                case 'propose_decrease_support':    $newL = max(self::LEVEL_MIN, $prevL - 1); $outcome = 'proposta'; break;
                case 'restore_support':             $newL = min(self::LEVEL_MAX, (int)$lastFade['prev_support_level']); break;
                case 'suggest_alternative_strategy': $outcome = 'proposta'; break;
            }
            // Preferenza dello studente: livello di supporto bloccato.
            $prefs = $model['preferences'] ?? [];
            if ($newL !== $prevL && !empty($prefs['lock_support_level'])) {
                $newL = $prevL;
                $outcome = 'sospesa_per_preferenza';
            }
            // Nessun effetto possibile (già al limite): non si registra un adattamento vuoto.
            if ($newL === $prevL && $newS === $prevS && $action !== 'suggest_alternative_strategy' && $outcome !== 'sospesa_per_preferenza') {
                continue;
            }

            return [
                'source' => 'regola',
                'rule_key' => $rule['key'],
                'rule_snapshot' => $rule,
                'action' => $action,
                'outcome' => $outcome, // 'proposta' = richiede l'accordo dello studente prima dell'applicazione
                'indicators' => $ind['values'],
                'evidence_event_ids' => $evidence,
                'prev_support_level' => $prevL, 'new_support_level' => $newL,
                'prev_step_size' => $prevS, 'new_step_size' => $newS,
            ];
        }
        return null;
    }

    private static function lastApplied(array $history, string $action): ?array
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]['action'] === $action && $history[$i]['outcome'] === 'applicata') {
                return $history[$i];
            }
        }
        return null;
    }

    private static function compare(float $a, string $op, float $b): bool
    {
        return match ($op) {
            '>=' => $a >= $b, '>' => $a > $b, '<=' => $a <= $b, '<' => $a < $b, '==' => $a == $b,
            default => false,
        };
    }
}
