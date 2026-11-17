<?php
declare(strict_types=1);

namespace Ablet\Service;

/** Riconoscimento lessicale delle richieste dirette della soluzione (aiuto esecutivo). Euristico. */
final class SolutionRequest
{
    private const RE = '/\b(dimmi|dammi|scrivimi|fammi|dai|dì)\b.{0,30}\b(la )?(risposta|soluzion\w*|riassunto|mappa|tema|testo)\b|\b(fallo|falla|fammelo|risolvilo|scrivilo)\s+tu\b|\b(qual è|quale è) la (risposta|soluzione)\b/iu';

    public static function matches(string $text): bool
    {
        return (bool)preg_match(self::RE, $text);
    }
}
