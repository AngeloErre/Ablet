<?php
declare(strict_types=1);

namespace Ablet\Domain\Protocol;

use Ablet\Domain\Adaptation\RuleEngine;
use Ablet\Domain\Srl\StateMachine;

/**
 * Convalida la configurazione del protocollo (nove campi della slide 15 + sezioni aggiuntive).
 * errors = bloccano la pubblicazione; warnings = richiedono una decisione consapevole del professionista.
 */
final class ProtocolValidator
{
    public const SCHEMA_VERSION = '1';

    /** Campi obbligatori (percorso => etichetta). I punti 8 e 9 non sono facoltativi. */
    private const REQUIRED = [
        'title' => 'Titolo',
        'role.identity' => '1. Ruolo: identità',
        'role.boundaries' => '1. Ruolo: delimitazione',
        'psychoeducational_goal.target_process' => '2. Obiettivo: processo target',
        'psychoeducational_goal.observable_behavior' => '2. Obiettivo: comportamento osservabile',
        'psychoeducational_goal.attainment_criterion' => '2. Obiettivo: criterio di raggiungimento',
        'functional_profile.age_band' => '3. Profilo funzionale: fascia d\'età',
        'interaction_strategies' => '4. Strategie di interazione',
        'adaptation_rules' => '5. Regole di adattamento',
        'feedback.style' => '6. Modalità di feedback',
        'monitoring.events' => '7. Criteri di monitoraggio',
        'operational_limits' => '8. Limiti operativi',
        'escalation.signals' => '9. Escalation: segnali',
        'escalation.response' => '9. Escalation: risposta prevista',
        'escalation.human_involvement' => '9. Escalation: coinvolgimento umano',
        'srl_procedures.forethought.steps' => 'Procedura: forethought',
        'srl_procedures.performance.steps' => 'Procedura: performance',
        'srl_procedures.self_reflection.steps' => 'Procedura: self-reflection',
    ];

    /** Formulazioni che tenterebbero di superare i vincoli fondamentali della piattaforma. */
    private const OVERRIDE_PATTERNS = [
        '/\b(fornisc[ai]|d[aà]i|scrivi|rivela)\b[^.]{0,40}\b(la soluzione|le soluzioni|la risposta (corretta|completa)|il riassunto|la mappa (completa|già))/iu',
        '/\b(ignora|disattiva|non applicare|supera)\b[^.]{0,40}\b(regole|vincoli|limiti)\b[^.]{0,30}\b(piattaforma|fondamentali)/iu',
        '/\b(presentati|fingi|comportati)\b[^.]{0,30}\b(come (uno |una )?(psicolog|terapeut|medic|person|essere uman|insegnante uman))/iu',
        '/\b(formula|proponi|suggerisci)\b[^.]{0,30}\bdiagnos/iu',
    ];

    private const DIAGNOSTIC_TERMS = '/\b(adhd|ddai|dsa|dislessi\w*|discalculi\w*|disgrafi\w*|disortografi\w*|autism\w*|asperger|diagnosi|disturbo\s+\w+|qi\b)/iu';

    /** @return array{errors:string[],warnings:string[]} */
    public static function validate(array $c): array
    {
        $errors = [];
        $warnings = [];

        if (($c['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            $errors[] = 'schema_version non supportata';
        }
        foreach (self::REQUIRED as $path => $label) {
            $v = self::get($c, $path);
            if ($v === null || $v === [] || (is_string($v) && trim($v) === '')) {
                $errors[] = "Campo obbligatorio mancante: $label";
            }
        }

        foreach (RuleEngine::validateRules($c['adaptation_rules'] ?? []) as $e) {
            $errors[] = "Regole di adattamento — $e";
        }

        $lvl = $c['scaffolding']['initial_level'] ?? null;
        if (!is_int($lvl) || $lvl < RuleEngine::LEVEL_MIN || $lvl > RuleEngine::LEVEL_MAX) {
            $errors[] = 'Livello iniziale di scaffolding non valido (0–3)';
        }
        foreach ($c['srl_procedures']['forethought']['required_slots'] ?? [] as $slot) {
            if (!in_array($slot, StateMachine::FORETHOUGHT_SLOTS, true)) {
                $errors[] = "Slot di pianificazione sconosciuto: $slot";
            }
        }
        $min = $c['srl_procedures']['forethought']['min_micro_goals'] ?? 1;
        $max = $c['srl_procedures']['forethought']['max_micro_goals'] ?? 5;
        if (!is_int($min) || !is_int($max) || $min < 1 || $max < $min || $max > 10) {
            $errors[] = 'Numero di micro-obiettivi non valido (min ≥ 1, max ≥ min, max ≤ 10)';
        }
        if (empty($c['modality']['text'])) {
            $errors[] = 'La modalità testuale deve restare sempre disponibile come alternativa alla voce';
        }
        if (!empty($c['provider']['fallback']) && empty($c['provider']['fallback']['acknowledged_data_destination_change'])) {
            $errors[] = 'Il fallback verso un altro fornitore cambia la destinazione dei dati: va confermato esplicitamente';
        }

        // Personalizzazione professionale entro i vincoli di piattaforma (controllo euristico, non esaustivo).
        foreach (self::strings($c) as $path => $text) {
            foreach (self::OVERRIDE_PATTERNS as $re) {
                if (preg_match($re, $text) && !preg_match('/\b(non|mai|nessun[ao]?|vietato|evita)\b/iu', $text)) {
                    $errors[] = "Contrasto con i vincoli fondamentali della piattaforma in '$path'";
                    break;
                }
            }
        }
        // Minimizzazione: nel profilo funzionale si descrive il funzionamento, non la diagnosi.
        foreach (self::strings($c['functional_profile'] ?? []) as $path => $text) {
            if (preg_match(self::DIAGNOSTIC_TERMS, $text)) {
                $warnings[] = "Profilo funzionale ('$path'): compare un termine diagnostico. Descrivere esigenze e punti di forza in termini funzionali.";
            }
        }
        if (empty($c['timing']['checkpoint_every_steps']) && empty($c['timing']['checkpoint_every_minutes'])) {
            $warnings[] = 'Nessun intervallo di checkpoint definito: l\'automonitoraggio non verrà sollecitato.';
        }
        $hasFade = false;
        foreach ($c['adaptation_rules'] ?? [] as $r) {
            $hasFade = $hasFade || (($r['then']['action'] ?? '') === 'propose_decrease_support');
        }
        if (!$hasFade) {
            $warnings[] = 'Nessuna regola di ritiro del supporto (fading): rischio di dipendenza funzionale dall\'agente.';
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    private static function get(array $a, string $path): mixed
    {
        foreach (explode('.', $path) as $k) {
            if (!is_array($a) || !array_key_exists($k, $a)) {
                return null;
            }
            $a = $a[$k];
        }
        return $a;
    }

    /** @return array<string,string> percorso => testo */
    private static function strings(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $p = $prefix === '' ? (string)$k : "$prefix.$k";
            if (is_string($v)) {
                $out[$p] = $v;
            } elseif (is_array($v)) {
                $out += self::strings($v, $p);
            }
        }
        return $out;
    }
}
