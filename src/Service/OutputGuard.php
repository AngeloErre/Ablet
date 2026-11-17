<?php
declare(strict_types=1);

namespace Ablet\Service;

/**
 * Controlli deterministici sul messaggio dell'agente prima che raggiunga lo studente.
 * Intercettano violazioni riconoscibili dalla forma; non possono stabilire se un testo «contiene la soluzione».
 */
final class OutputGuard
{
    private const COPY_WORDS = 14;

    /** @param string[] $materials @return array{block:string[],flags:string[]} */
    public static function check(string $message, array $materials): array
    {
        $block = [];
        $flags = [];
        if (preg_match('/\b(adhd|ddai|dislessi\w*|discalculi\w*|autism\w*|asperger|hai un disturbo|soffri di)\b/iu', $message)) {
            $block[] = 'etichetta_diagnostica';
        }
        if (preg_match('/\b(sono|parla)\b[^.?!]{0,25}\b(uno psicologo|una psicologa|un terapeuta|una persona (vera|reale)|un essere umano|il tuo (psicologo|insegnante))\b/iu', $message)
            && !preg_match('/\bnon\s+(sono|parla)\b/iu', $message)) {
            $block[] = 'identità_umana_o_professionale';
        }
        if (preg_match('/\b(qualcuno|lo psicologo|un adulto)\b[^.?!]{0,30}\b(sta leggendo|ti contatterà|interverrà|è stato avvisato)\b/iu', $message)
            && !preg_match('/\b(nessuno|non)\b/iu', $message)) {
            $block[] = 'promessa_di_intervento';
        }
        $norm = static fn(string $s): array => preg_split('/\s+/u', trim(preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($s))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $mw = $norm($message);
        if (count($mw) >= self::COPY_WORDS) {
            foreach ($materials as $mat) {
                $hay = ' ' . implode(' ', $norm($mat)) . ' ';
                for ($i = 0; $i + self::COPY_WORDS <= count($mw); $i++) {
                    if (str_contains($hay, ' ' . implode(' ', array_slice($mw, $i, self::COPY_WORDS)) . ' ')) {
                        $block[] = 'riproduzione_estesa_del_materiale';
                        break 2;
                    }
                }
            }
        }
        if (substr_count($message, '?') > 2) {
            $flags[] = 'più_richieste_nello_stesso_turno';
        }
        return ['block' => $block, 'flags' => $flags];
    }
}
