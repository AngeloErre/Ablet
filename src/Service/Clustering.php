<?php
declare(strict_types=1);

namespace Ablet\Service;

/**
 * k-means deterministico (inizializzazione «farthest-first», distanza euclidea, max 25 iterazioni)
 * sui profili di sessione: proporzione di segmenti per codice del codebook.
 * Analisi esplorativa e descrittiva: i gruppi dipendono da k e dal numero di sessioni e non sono tipologie di studenti.
 */
final class Clustering
{
    /** @param array<int|string,float[]> $vectors id => vettore @return array{k:int,assignment:array<int|string,int>,centroids:float[][]} */
    public static function kmeans(array $vectors, int $k): array
    {
        $ids = array_keys($vectors);
        $n = count($ids);
        $k = max(1, min($k, $n));
        if ($n === 0) { return ['k' => 0, 'assignment' => [], 'centroids' => []]; }
        $dist = static function (array $a, array $b): float { $s = 0.0; foreach ($a as $i => $v) { $s += ($v - $b[$i]) ** 2; } return $s; };
        $cent = [$vectors[$ids[0]]];
        while (count($cent) < $k) {
            $best = null; $bd = -1.0;
            foreach ($ids as $id) {
                $d = min(array_map(static fn($c) => $dist($vectors[$id], $c), $cent));
                if ($d > $bd) { $bd = $d; $best = $id; }
            }
            $cent[] = $vectors[$best];
        }
        $assign = [];
        for ($it = 0; $it < 25; $it++) {
            $changed = false;
            foreach ($ids as $id) {
                $b = 0; $bd = INF;
                foreach ($cent as $ci => $c) { $d = $dist($vectors[$id], $c); if ($d < $bd) { $bd = $d; $b = $ci; } }
                if (($assign[$id] ?? -1) !== $b) { $assign[$id] = $b; $changed = true; }
            }
            if (!$changed) { break; }
            foreach ($cent as $ci => $c) {
                $members = array_keys($assign, $ci, true);
                if (!$members) { continue; }
                foreach ($c as $dim => $_) { $cent[$ci][$dim] = array_sum(array_map(static fn($m) => $vectors[$m][$dim], $members)) / count($members); }
            }
        }
        return ['k' => $k, 'assignment' => $assign, 'centroids' => array_map(static fn($c) => array_map(static fn($v) => round($v, 3), $c), $cent)];
    }
}
