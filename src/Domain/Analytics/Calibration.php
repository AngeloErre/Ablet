<?php
declare(strict_types=1);

namespace Ablet\Domain\Analytics;

/**
 * Calibrazione sicurezza–prestazione (cfr. monitoraggio metacognitivo: Nelson & Narens, 1990).
 * Calcolabile soltanto se, per lo stesso elemento, esistono:
 *   - un giudizio di sicurezza dello studente su scala nota;
 *   - una valutazione di prestazione attribuita dal professionista con rubrica esplicita su scala nota.
 * Le due scale sono normalizzate su 0–1. In ogni altro caso il risultato è "non calcolabile".
 */
final class Calibration
{
    public const MIN_PAIRS = 1;

    /**
     * @param array<int,array{confidence:?float,conf_min:?float,conf_max:?float,performance:?float,perf_min:?float,perf_max:?float,rubric_id:?int}> $items
     * @return array{status:string,reason?:string,n:int,n_excluded:int,bias?:float,absolute_accuracy?:float}
     */
    public static function compute(array $items): array
    {
        $pairs = [];
        $excluded = 0;
        foreach ($items as $it) {
            $ok = isset($it['confidence'], $it['conf_min'], $it['conf_max'], $it['performance'], $it['perf_min'], $it['perf_max'], $it['rubric_id'])
                && $it['conf_max'] > $it['conf_min'] && $it['perf_max'] > $it['perf_min'];
            if (!$ok) {
                $excluded++;
                continue;
            }
            $c = ($it['confidence'] - $it['conf_min']) / ($it['conf_max'] - $it['conf_min']);
            $p = ($it['performance'] - $it['perf_min']) / ($it['perf_max'] - $it['perf_min']);
            $pairs[] = [min(1.0, max(0.0, $c)), min(1.0, max(0.0, $p))];
        }
        $n = count($pairs);
        if ($n < self::MIN_PAIRS) {
            return [
                'status' => 'non_calcolabile',
                'reason' => $items === [] ? 'nessun giudizio di sicurezza registrato'
                    : 'manca una valutazione della prestazione con rubrica esplicita e scala confrontabile',
                'n' => 0, 'n_excluded' => $excluded,
            ];
        }
        $bias = 0.0;
        $abs = 0.0;
        foreach ($pairs as [$c, $p]) {
            $bias += $c - $p;
            $abs += abs($c - $p);
        }
        return [
            'status' => 'calcolata',
            'n' => $n, 'n_excluded' => $excluded,
            'bias' => round($bias / $n, 3),              // >0 sovrastima, <0 sottostima (scala 0–1)
            'absolute_accuracy' => round($abs / $n, 3),  // 0 = calibrazione perfetta
        ];
    }
}
