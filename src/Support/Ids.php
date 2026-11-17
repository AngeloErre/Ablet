<?php
declare(strict_types=1);

namespace Ablet\Support;

final class Ids
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // senza caratteri ambigui

    /** Identificatore pseudonimo casuale, non derivato da dati personali. */
    public static function pseudonym(int $length = 12): string
    {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }
        return $out;
    }

    public static function nonce(): string
    {
        return bin2hex(random_bytes(8));
    }
}
