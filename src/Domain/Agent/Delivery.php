<?php
declare(strict_types=1);

namespace Ablet\Domain\Agent;

/**
 * Consegna a voce: i messaggi dell'agente sono pensati per essere ascoltati, non letti.
 *
 * Razionale (proposta progettuale, da validare): l'ascolto è sequenziale e grava sulla memoria
 * di lavoro fonologica (Baddeley); un messaggio parlato efficace è quindi breve, con frasi
 * corte, senza elenchi o simboli che la sintesi vocale legge male, e con l'unica richiesta
 * collocata alla fine (effetto recency), così che sia l'ultima cosa udita prima del turno dello studente.
 * Le soglie di lunghezza per fascia d'età sono parametri iniziali, non valori normativi.
 */
final class Delivery
{
    public const VERSION = '0.2.0';

    /** fascia => [parole massime per messaggio, parole massime per frase] */
    private const LIMITS = [
        '8-10' => [30, 10],
        '11-13' => [40, 12],
        '14-16' => [50, 15],
        '17-19' => [60, 18],
        'adulto' => [60, 18],
    ];

    /** @return array{0:int,1:int} */
    public static function limits(?string $ageBand): array
    {
        return self::LIMITS[$ageBand ?? ''] ?? self::LIMITS['11-13'];
    }

    public static function instructions(?string $ageBand): string
    {
        [$max, $sent] = self::limits($ageBand);
        $band = $ageBand ?: 'non indicata';
        return <<<TXT
CONSEGNA A VOCE (regola della piattaforma, versione 0.2.0). Il campo student_message verrà letto ad alta voce da una sintesi vocale a uno studente che ascolta da un tablet. Fascia d'età: {$band}.
- Al massimo {$max} parole in tutto e frasi di al massimo {$sent} parole. Una sola idea per frase.
- Una sola domanda o richiesta, sempre alla fine del messaggio: è l'ultima cosa che lo studente sente prima di rispondere.
- Niente elenchi, titoli, grassetto, emoji, link, parentesi, barre, frecce o abbreviazioni: la voce li legge male.
- Scrivi i numeri piccoli in parole se rendono la frase più naturale ("da uno a cinque").
- Parla come si parla, non come si scrive: usa "dimmi", "raccontami", "quando hai finito dimmi fatto". Non usare "scrivi", "leggi qui sotto", "clicca" o "premi".
- Se citi un passo del piano, citane solo l'inizio, con parole brevi.
- Tono caldo e diretto, mai infantile; lessico semplice senza impoverire i concetti.
TXT;
    }

    /** Pulisce il testo per la sintesi vocale senza cambiarne il contenuto. */
    public static function sanitize(string $t): string
    {
        $t = preg_replace('~https?://\S+|www\.\S+~u', '', $t) ?? $t;
        $t = preg_replace('~[*_`#]+~u', '', $t) ?? $t;
        $t = preg_replace('~^\s*(?:[-•–]|\d+[.)])\s+~mu', '', $t) ?? $t;   // marcatori di elenco
        $t = preg_replace('~\s*(?:→|->|=>)\s*~u', ', poi ', $t) ?? $t;
        $t = preg_replace('~[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]~u', '', $t) ?? $t; // emoji e simboli
        $t = preg_replace('~\b(\d)\s*[-–]\s*(\d)\b~u', 'da $1 a $2', $t) ?? $t;     // "1-5"
        $t = preg_replace('~\(([^()]{0,80})\)~u', ', $1,', $t) ?? $t;              // parentesi → incisi
        $t = preg_replace('~([^\s.!?:;])\s*\R+\s*~u', '$1. ', $t) ?? $t;           // a capo senza punteggiatura
        $t = preg_replace('~\s*\R+\s*~u', ' ', $t) ?? $t;
        $t = preg_replace('~\s+([,.;:!?])~u', '$1', $t) ?? $t;
        $t = preg_replace('~,\s*([.!?])~u', '$1', $t) ?? $t;
        $t = preg_replace('~([,;:])(?=\S)~u', '$1 ', $t) ?? $t;
        $t = preg_replace('~,\s*,~u', ',', $t) ?? $t;
        $t = trim(preg_replace('~\s{2,}~u', ' ', $t) ?? $t, " \t,;");
        if ($t !== '' && !preg_match('~[.!?…»"]$~u', $t)) { $t .= '.'; }
        return $t;
    }

    public static function words(string $t): int
    {
        return count(preg_split('~\s+~u', trim($t), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /**
     * Problemi che giustificano UNA nuova richiesta al modello. Un messaggio troppo lungo non viene troncato:
     * il troncamento potrebbe eliminare proprio la richiesta finale.
     * @return list<string>
     */
    public static function issues(string $t, ?string $ageBand): array
    {
        [$max] = self::limits($ageBand);
        $out = [];
        if (self::words($t) > (int)ceil($max * 1.4)) { $out[] = 'troppo_lungo'; }
        if (substr_count($t, '?') > 2) { $out[] = 'troppe_domande'; }
        if (preg_match('~\b(scrivi|scrivere|clicca|premi)\b~iu', $t)) { $out[] = 'riferimento_alla_scrittura'; }
        return $out;
    }
}
