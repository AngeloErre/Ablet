<?php
declare(strict_types=1);

namespace Ablet\Support;

/**
 * Cifratura delle chiavi API a riposo. La chiave applicativa (32 byte, base64) sta solo in .env.
 * Formato: "S1" + nonce + testo cifrato (libsodium secretbox) oppure "O1" + iv + tag + testo cifrato (OpenSSL AES-256-GCM),
 * a seconda dell'estensione disponibile sull'hosting. Entrambi i formati restano leggibili se l'estensione è presente.
 */
final class Secrets
{
    private static function key(): string
    {
        $b64 = Env::get('APP_SECRET_KEY', '');
        $key = $b64 !== '' ? base64_decode($b64, true) : false;
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('APP_SECRET_KEY assente o non valida: impossibile gestire chiavi API.');
        }
        return $key;
    }

    public static function engine(): ?string
    {
        if (function_exists('sodium_crypto_secretbox')) { return 'sodium'; }
        if (function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)) { return 'openssl'; }
        return null;
    }

    public static function available(): bool
    {
        try { self::key(); return self::engine() !== null; } catch (\RuntimeException) { return false; }
    }

    public static function encrypt(string $plain): string
    {
        $key = self::key();
        if (self::engine() === 'sodium') {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            return 'S1' . $nonce . sodium_crypto_secretbox($plain, $nonce, $key);
        }
        if (self::engine() === 'openssl') {
            $iv = random_bytes(12);
            $ct = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            return 'O1' . $iv . $tag . $ct;
        }
        throw new \RuntimeException('Né sodium né OpenSSL sono disponibili: impossibile cifrare le chiavi API.');
    }

    public static function decrypt(string $blob): string
    {
        $key = self::key();
        $plain = false;
        $tagged = substr($blob, 0, 2);
        if ($tagged === 'O1' && function_exists('openssl_decrypt')) {
            $plain = openssl_decrypt(substr($blob, 30), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($blob, 2, 12), substr($blob, 14, 16));
        } elseif (function_exists('sodium_crypto_secretbox_open')) {
            $b = $tagged === 'S1' ? substr($blob, 2) : $blob; // formato senza prefisso: versioni precedenti
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $plain = sodium_crypto_secretbox_open(substr($b, $n), substr($b, 0, $n), $key);
        }
        if ($plain === false) {
            throw new \RuntimeException('Chiave API non decifrabile (APP_SECRET_KEY cambiata o estensione mancante).');
        }
        return $plain;
    }
}
