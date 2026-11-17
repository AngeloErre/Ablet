<?php
declare(strict_types=1);

namespace Ablet\Domain\Agent;

/**
 * Segnalazione euristica di istruzioni incorporate nei materiali (prompt injection).
 * Non blocca il materiale e non è esaustiva: produce avvisi per il professionista.
 * La difesa principale resta strutturale (PromptCompiler: materiali come dati sigillati).
 */
final class MaterialScanner
{
    private const PATTERNS = [
        'ignora_istruzioni' => '/\b(ignora|dimentica|trascura)\b.{0,40}\b(istruzioni|regole|prompt|vincoli)/iu',
        'ignore_instructions' => '/\b(ignore|disregard|forget)\b.{0,40}\b(instructions|rules|prompt)/i',
        'cambio_ruolo' => '/\b(da ora|d\'ora in poi|from now on|you are now|ora sei)\b/iu',
        'richiesta_soluzione' => '/\b(fornisci|rivela|scrivi|dai)\b.{0,40}\b(soluzion|rispost[ae] corrett|riassunto complet)/iu',
        'estrazione_prompt' => '/\b(rivela|mostra|stampa|ripeti|reveal|print)\b.{0,40}\b(prompt|istruzioni di sistema|system)/iu',
        'marcatori_di_sistema' => '/(<<<|>>>|\bsystem\s*:|\bassistant\s*:|\[INST\])/i',
    ];

    /** @return array<int,array{flag:string,excerpt:string}> */
    public static function scan(string $text): array
    {
        $flags = [];
        foreach (self::PATTERNS as $flag => $re) {
            if (preg_match($re, $text, $m, PREG_OFFSET_CAPTURE)) {
                $start = max(0, $m[0][1] - 20);
                $flags[] = ['flag' => $flag, 'excerpt' => trim(mb_strcut($text, $start, 120))];
            }
        }
        return $flags;
    }
}
