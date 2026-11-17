<?php
declare(strict_types=1);

namespace Ablet\Service;

/**
 * Rete di sicurezza lessicale sui messaggi dello studente, indipendente dall'LLM.
 * Copre poche formulazioni esplicite: riduce il rischio di mancata rilevazione, non lo elimina.
 */
final class SafetyNet
{
    private const EMERGENCY = '/\b(suicid\w*|uccider(mi|ci)|ammazzar(mi|ci)|farla finita|togliermi la vita|non voglio più vivere|farmi (del )?male|tagliarmi)\b/iu';
    private const DISTRESS = '/\b(mi (picchia|picchiano|menano|minaccia|minacciano|fa del male|fanno del male)|ho paura di tornare a casa|nessuno mi vuole|vorrei sparire|non ce la faccio più)\b/iu';

    public const MSG_DISTRESS = 'Quello che mi hai detto è importante. Viene prima dello studio, quindi mettiamo in pausa. '
        . 'Io sono un\'intelligenza artificiale, non una persona. Nessuno sta leggendo questa conversazione adesso. '
        . 'Parlane con un adulto di cui ti fidi: un genitore, un insegnante o il tuo psicologo. '
        . 'Quando vuoi, puoi riprendere o chiudere.';
    public const MSG_EMERGENCY = 'Quello che mi hai detto è molto importante. Ho messo in pausa lo studio. '
        . 'Io sono un\'intelligenza artificiale e nessuno sta leggendo questa conversazione adesso. '
        . 'Se sei in pericolo, parla subito con un adulto vicino a te. Oppure chiama il 112.';
    public const MSG_HOLD = 'Lo studio è in pausa. Se ne hai bisogno, parla con un adulto di cui ti fidi. Quando vuoi, puoi riprendere o chiudere.';

    public static function classify(string $text): string
    {
        if (preg_match(self::EMERGENCY, $text)) { return 'emergency'; }
        if (preg_match(self::DISTRESS, $text)) { return 'distress'; }
        return 'ok';
    }
}
