<?php
declare(strict_types=1);

namespace Ablet\Service;

use Ablet\Support\Database;

/**
 * Accordo fra codificatori sul codebook, a livello di messaggio dello studente e per codice (presenza/assenza).
 *
 *  - Codifica umana di riferimento (A): annotazioni valide sui messaggi completamente revisionati dal professionista
 *    (nessuna proposta automatica ancora «da rivedere»): umane, oppure automatiche confermate.
 *  - Secondo codificatore (B): file CSV compilato «alla cieca» a partire dall'esportazione (message_id;codici).
 *  - Modello (M): proposte automatiche originali, prima della revisione.
 *
 * Statistiche per codice: kappa di Cohen per dati dicotomici, accordo percentuale, prevalenza; per il modello anche
 * precisione e richiamo rispetto alla revisione umana. Con meno di 5 casi positivi la kappa è instabile e viene segnalata.
 * Il kappa è calcolato senza intervalli di confidenza: per la pubblicazione usare il file di dati e un software statistico.
 */
final class Agreement
{
    public const MIN_POSITIVE = 5;

    private static function codes(): array
    {
        return array_column(Annotator::codebook()['content']['codes'], 'key');
    }

    /** Messaggi dello studente nelle sessioni del professionista. */
    private static function messages(int $ownerId, ?array $ids = null): array
    {
        $sql = "SELECT m.id, m.session_id, m.phase, m.content, a.content AS prompt FROM messages m JOIN sessions s ON s.id = m.session_id
            JOIN activities ac ON ac.id = s.activity_id AND ac.owner_id = ? LEFT JOIN messages a ON a.id = m.in_reply_to
            WHERE m.role = 'student' AND m.phase <> 'safety_hold'";
        $args = [$ownerId];
        if ($ids !== null) {
            if ($ids === []) { return []; }
            $sql .= ' AND m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $args = array_merge($args, $ids);
        }
        $st = Database::pdo()->prepare($sql . ' ORDER BY m.id');
        $st->execute($args);
        return $st->fetchAll();
    }

    /** @return array{human:array<int,string[]>,model:array<int,string[]>,reviewed:int[]} per message_id */
    private static function codings(array $messageIds): array
    {
        $human = $model = $pending = [];
        if ($messageIds !== []) {
            $st = Database::pdo()->prepare('SELECT message_id, code_key, annotator_type, review_status FROM text_annotations WHERE message_id IN ('
                . implode(',', array_fill(0, count($messageIds), '?')) . ')');
            $st->execute($messageIds);
            foreach ($st->fetchAll() as $a) {
                $mid = (int)$a['message_id'];
                if ($a['annotator_type'] === 'modello') {
                    if ($a['code_key'] !== null) { $model[$mid][$a['code_key']] = true; } // proposte originali, prima della revisione
                    if ($a['review_status'] === 'da_rivedere') { $pending[$mid] = true; }
                }
                $valid = $a['code_key'] !== null && ($a['annotator_type'] === 'umano' ? $a['review_status'] !== 'rifiutata' && $a['review_status'] !== 'corretta' : $a['review_status'] === 'confermata');
                if ($valid) { $human[$mid][$a['code_key']] = true; }
                $human[$mid] ??= [];
            }
        }
        $reviewed = array_values(array_filter($messageIds, static fn($m) => isset($human[$m]) && !isset($pending[$m])));
        return ['human' => array_map('array_keys', $human), 'model' => array_map('array_keys', $model), 'reviewed' => $reviewed];
    }

    /** Kappa di Cohen per due vettori dicotomici. */
    public static function kappa(array $a, array $b): array
    {
        $n = count($a);
        if ($n === 0) {
            return ['n' => 0, 'kappa' => null, 'agreement' => null, 'pos_a' => 0, 'pos_b' => 0, 'note' => 'nessun caso'];
        }
        $both = $onlyA = $onlyB = $none = 0;
        foreach ($a as $i => $x) {
            $y = $b[$i];
            if ($x && $y) { $both++; } elseif ($x) { $onlyA++; } elseif ($y) { $onlyB++; } else { $none++; }
        }
        $po = ($both + $none) / $n;
        $pa = ($both + $onlyA) / $n;
        $pb = ($both + $onlyB) / $n;
        $pe = $pa * $pb + (1 - $pa) * (1 - $pb);
        $k = abs(1 - $pe) < 1e-12 ? null : ($po - $pe) / (1 - $pe);
        $posA = $both + $onlyA;
        $posB = $both + $onlyB;
        $note = $k === null ? 'non calcolabile: tutti i casi nella stessa categoria' : (min($posA, $posB) < self::MIN_POSITIVE ? 'instabile: meno di ' . self::MIN_POSITIVE . ' casi positivi' : null);
        return ['n' => $n, 'kappa' => $k === null ? null : round($k, 3), 'agreement' => round($po, 3), 'pos_a' => $posA, 'pos_b' => $posB,
            'both' => $both, 'only_a' => $onlyA, 'only_b' => $onlyB, 'none' => $none, 'note' => $note];
    }

    private static function perCode(array $ids, array $setA, array $setB): array
    {
        $out = [];
        foreach (self::codes() as $c) {
            $va = $vb = [];
            foreach ($ids as $m) {
                $va[] = in_array($c, $setA[$m] ?? [], true);
                $vb[] = in_array($c, $setB[$m] ?? [], true);
            }
            $k = self::kappa($va, $vb);
            $k['precision'] = ($k['both'] ?? 0) + ($k['only_b'] ?? 0) > 0 ? round($k['both'] / ($k['both'] + $k['only_b']), 3) : null; // B rispetto ad A
            $k['recall'] = ($k['both'] ?? 0) + ($k['only_a'] ?? 0) > 0 ? round($k['both'] / ($k['both'] + $k['only_a']), 3) : null;
            $out[$c] = $k;
        }
        $vals = array_filter(array_column($out, 'kappa'), static fn($v) => $v !== null);
        return ['codes' => $out, 'mean_kappa' => $vals ? round(array_sum($vals) / count($vals), 3) : null, 'n_messages' => count($ids)];
    }

    /** Accordo fra proposte automatiche originali e revisione umana. */
    public static function modelVsHuman(int $ownerId): array
    {
        $ids = array_map('intval', array_column(self::messages($ownerId), 'id'));
        $c = self::codings($ids);
        $reviewed = array_values(array_filter($c['reviewed'], static fn($m) => isset($c['model'][$m]) || isset($c['human'][$m])));
        return self::perCode($reviewed, $c['human'], $c['model']) + ['reference' => 'revisione umana (A) vs proposte automatiche (B)'];
    }

    /**
     * Esportazione per la codifica cieca: nessun codice, ordine casuale riproducibile, campione stratificato per fase.
     * @return array<int,array<string,string>>
     */
    public static function export(int $ownerId, int $perPhase, int $seed, bool $reviewedOnly): array
    {
        $msgs = self::messages($ownerId);
        if ($reviewedOnly) {
            $ok = array_flip(self::codings(array_map('intval', array_column($msgs, 'id')))['reviewed']);
            $msgs = array_values(array_filter($msgs, static fn($m) => isset($ok[(int)$m['id']])));
        }
        mt_srand($seed);
        $byPhase = [];
        foreach ($msgs as $m) { $byPhase[$m['phase']][] = $m; }
        $rows = [];
        foreach ($byPhase as $phase => $list) {
            usort($list, static fn() => mt_rand(-1, 1));
            foreach (array_slice($list, 0, $perPhase > 0 ? $perPhase : count($list)) as $m) {
                $rows[] = ['message_id' => (string)$m['id'], 'fase' => $phase, 'produzione' => $m['prompt'] !== null && str_contains((string)$m['prompt'], '?') ? 'sollecitata' : 'spontanea',
                    'messaggio_precedente_agente' => (string)$m['prompt'], 'messaggio_studente' => (string)$m['content'], 'codici' => ''];
            }
        }
        mt_srand();
        return $rows;
    }

    /** CSV con protezione dall'interpretazione di formule nei fogli di calcolo. */
    public static function toCsv(array $rows): string
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        if ($rows) { fputcsv($f, array_keys($rows[0]), ';', '"', ''); }
        foreach ($rows as $r) {
            fputcsv($f, array_map(static fn($v) => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v, $r), ';', '"', '');
        }
        rewind($f);
        return (string)stream_get_contents($f);
    }

    /**
     * Confronto con un secondo codificatore. CSV con colonne message_id e codici (separati da | , o spazio).
     * @return array<string,mixed>
     */
    public static function withSecondCoder(int $ownerId, string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? '';
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            throw new \DomainException('file_vuoto');
        }
        $sep = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(static fn($h) => mb_strtolower(trim($h)), str_getcsv($lines[0], $sep, '"', ''));
        $iId = array_search('message_id', $head, true);
        $iC = array_search('codici', $head, true);
        if ($iId === false || $iC === false) {
            throw new \DomainException('colonne_message_id_e_codici_richieste');
        }
        $valid = self::codes();
        $b = [];
        $unknown = [];
        $text = str_replace("\r", '', $csv);
        $rows = [];
        // Rilettura con fgetcsv per gestire i campi su più righe.
        $f = fopen('php://temp', 'w+');
        fwrite($f, $text);
        rewind($f);
        fgetcsv($f, null, $sep, '"', '');
        while (($r = fgetcsv($f, null, $sep, '"', '')) !== false) { $rows[] = $r; }
        foreach ($rows as $r) {
            $mid = (int)preg_replace('/\D/', '', (string)($r[$iId] ?? ''));
            if ($mid <= 0) { continue; }
            $codes = array_filter(array_map(static fn($x) => strtoupper(trim($x)), preg_split('/[|, ]+/', (string)($r[$iC] ?? '')) ?: []));
            foreach ($codes as $c) {
                if (!in_array($c, $valid, true)) { $unknown[$c] = true; }
            }
            $b[$mid] = array_values(array_intersect($codes, $valid));
        }
        $own = array_map('intval', array_column(self::messages($ownerId, array_keys($b)), 'id')); // solo messaggi del professionista
        $c = self::codings($own);
        $ids = array_values(array_intersect($own, $c['reviewed']));
        $res = self::perCode($ids, $c['human'], $b);
        $dis = [];
        foreach ($ids as $m) {
            $x = $c['human'][$m] ?? [];
            $y = $b[$m] ?? [];
            sort($x); sort($y);
            if ($x !== $y) { $dis[] = ['message_id' => $m, 'a' => $x, 'b' => $y]; }
        }
        return $res + ['reference' => 'revisione umana (A) vs secondo codificatore (B)', 'rows_in_file' => count($b),
            'excluded_not_reviewed_or_not_owned' => count($b) - count($ids), 'unknown_codes' => array_keys($unknown), 'disagreements' => array_slice($dis, 0, 200)];
    }
}
