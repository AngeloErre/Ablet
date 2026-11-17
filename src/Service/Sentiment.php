<?php
declare(strict_types=1);

namespace Ablet\Service;

/**
 * Polarità lessicale dei messaggi dello studente — algoritmo ABLET-lex 0.1.
 *   1. testo in minuscolo, suddiviso in parole;
 *   2. ogni parola presente nel lessico interno vale +1 (positiva) o −1 (negativa);
 *   3. una negazione («non», «mai», «senza», «nessuno/a», «niente») nelle due parole precedenti inverte il segno;
 *   4. punteggio = (positive − negative) / (positive + negative), fra −1 e +1; nullo se nessuna parola è nel lessico.
 * Il lessico è interno, ridotto e NON validato: l'indice descrive le parole usate, non lo stato emotivo dello studente.
 */
final class Sentiment
{
    public const ALGORITHM = 'ABLET-lex 0.1 (conteggio su lessico interno di polarità con inversione per negazione; non validato)';

    private const POS = ['bene', 'benissimo', 'facile', 'chiaro', 'chiara', 'capito', 'riuscito', 'riuscita', 'contento', 'contenta', 'soddisfatto', 'soddisfatta',
        'bello', 'bella', 'interessante', 'tranquillo', 'tranquilla', 'sicuro', 'sicura', 'meglio', 'ottimo', 'utile', 'piace', 'piaciuto', 'felice', 'fiero', 'fiera',
        'divertente', 'semplice', 'pronto', 'pronta', 'aiutato', 'funzionato', 'funziona', 'veloce', 'giusto', 'bravo', 'brava', 'orgoglioso', 'orgogliosa', 'sereno', 'serena'];
    private const NEG = ['male', 'malissimo', 'difficile', 'difficilissimo', 'confuso', 'confusa', 'bloccato', 'bloccata', 'stanco', 'stanca', 'noioso', 'noiosa', 'annoiato', 'annoiata',
        'arrabbiato', 'arrabbiata', 'nervoso', 'nervosa', 'triste', 'peggio', 'impossibile', 'sbagliato', 'sbagliata', 'errore', 'errori', 'paura', 'ansia', 'ansioso', 'ansiosa',
        'preoccupato', 'preoccupata', 'odio', 'brutto', 'brutta', 'faticoso', 'faticosa', 'fatica', 'lento', 'lenta', 'perso', 'persa', 'inutile', 'stufo', 'stufa', 'deluso', 'delusa', 'innervosito', 'innervosita'];
    private const NEGATORS = ['non', 'mai', 'senza', 'nessuno', 'nessuna', 'niente', 'né'];

    /** @return array{score:?float,positive:int,negative:int,matched:string[]} */
    public static function score(string $text): array
    {
        $w = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $p = 0; $n = 0; $hit = [];
        foreach ($w as $i => $t) {
            $s = in_array($t, self::POS, true) ? 1 : (in_array($t, self::NEG, true) ? -1 : 0);
            if ($s === 0) { continue; }
            if (array_intersect(array_slice($w, max(0, $i - 2), min(2, $i)), self::NEGATORS)) { $s = -$s; $t = "non…$t"; }
            $s > 0 ? $p++ : $n++;
            $hit[] = $t;
        }
        return ['score' => ($p + $n) ? round(($p - $n) / ($p + $n), 2) : null, 'positive' => $p, 'negative' => $n, 'matched' => $hit];
    }
}
