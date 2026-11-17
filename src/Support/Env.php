<?php
declare(strict_types=1);

namespace Ablet\Support;

/** Lettura di un file .env semplice (CHIAVE=valore). Le variabili d'ambiente reali hanno la precedenza. */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $line, 2);
            self::$values[trim($k)] = trim(trim($v), "\"'");
        }
    }

    /**
     * Configurazione in formato PHP (config.php che restituisce un array). Preferibile su hosting condiviso:
     * anche se il server web servisse il file, il contenuto non verrebbe mostrato.
     */
    public static function loadPhp(string $path): void
    {
        if (!is_file($path)) {
            return;
        }
        $cfg = (static fn(string $f) => require $f)($path);
        if (is_array($cfg)) {
            foreach ($cfg as $k => $v) {
                if (is_scalar($v)) { self::$values[(string)$k] = (string)$v; }
            }
        }
    }

    public static function hasConfig(): bool
    {
        return isset(self::$values['DB_NAME']);
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $env = getenv($key);
        if ($env !== false && $env !== '') {
            return $env;
        }
        return self::$values[$key] ?? $default;
    }
}
