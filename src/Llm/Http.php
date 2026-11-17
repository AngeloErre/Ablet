<?php
declare(strict_types=1);

namespace Ablet\Llm;

/** Client HTTP minimale su cURL: timeout, retry controllati, interruzione se il client si disconnette. Non registra nulla. */
final class Http
{
    private const RETRYABLE = [408, 500, 502, 503, 504, 529];

    /** Scadenza complessiva (epoch, secondi) delle chiamate della richiesta HTTP corrente: evita che l'hosting interrompa lo script. */
    private static ?float $deadline = null;

    public static function setBudget(float $seconds): void
    {
        self::$deadline = microtime(true) + max(5.0, $seconds);
    }

    public static function remaining(): ?float
    {
        return self::$deadline === null ? null : self::$deadline - microtime(true);
    }

    /**
     * @param array<string,string> $headers
     * @return array{status:int,json:?array}
     */
    public static function request(string $method, string $url, array $headers, ?array $body, int $timeoutS, int $maxRetries): array
    {
        $attempt = 0;
        while (true) {
            $left = self::remaining();
            if ($left !== null && $left < 3) {
                throw new ProviderException('timeout', 'tempo disponibile esaurito');
            }
            $effective = $left === null ? $timeoutS : (int)max(2, min($timeoutS, floor($left)));
            $retryAfter = null;
            $ch = curl_init($url);
            $h = ['Content-Type: application/json', 'Accept: application/json'];
            foreach ($headers as $k => $v) {
                $h[] = "$k: $v";
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$retryAfter): int {
                    if (preg_match('/^HTTP\//i', $line)) { $retryAfter = null; }
                    if (stripos($line, 'Retry-After:') === 0) {
                        $value = trim(substr($line, 12));
                        $seconds = ctype_digit($value) ? (int)$value : (($at = strtotime($value)) !== false ? $at - time() : 0);
                        $retryAfter = max(1, min(86400, $seconds));
                    }
                    return strlen($line);
                },
                CURLOPT_HTTPHEADER => $h,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => min(10, $effective),
                CURLOPT_TIMEOUT => $effective,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_NOPROGRESS => false,
                // Cancellazione: se il browser ha chiuso la richiesta, si interrompe la chiamata al provider.
                CURLOPT_PROGRESSFUNCTION => static fn() => (PHP_SAPI !== 'cli' && connection_aborted()) ? 1 : 0,
            ]);
            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $cerr = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($errno === CURLE_ABORTED_BY_CALLBACK) {
                throw new ProviderException('cancelled');
            }
            // Quota: nessun retry immediato. Con Retry-After si restituisce subito il controllo al browser.
            $retryable = $errno !== 0 || ($retryAfter === null && in_array($status, self::RETRYABLE, true));
            if ($retryable && $attempt < $maxRetries && (self::remaining() ?? 99) > 8) {
                $attempt++;
                usleep((int)(400000 * (2 ** min($attempt - 1, 3))));
                continue;
            }
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new ProviderException('timeout');
            }
            if ($errno !== 0) {
                throw new ProviderException('unavailable', self::redact('errore di rete: ' . $cerr . " (cURL $errno)", $headers));
            }
            if ($status < 200 || $status >= 300) {
                // Messaggio d'errore del fornitore (senza la chiave), utile per capire il problema dal pannello.
                $ej = json_decode((string)$raw, true);
                $pm = is_array($ej) ? (string)($ej['error']['message'] ?? (is_string($ej['error'] ?? null) ? $ej['error'] : ($ej['message'] ?? ''))) : '';
                $pm = self::redact(mb_substr(trim($pm), 0, 220), $headers);
                if ($retryAfter === null && is_array($ej)) {
                    foreach (($ej['error']['details'] ?? []) as $detail) {
                        if (is_array($detail) && preg_match('/^(\d+(?:\.\d+)?)s$/', (string)($detail['retryDelay'] ?? ''), $m)) {
                            $retryAfter = max(1, min(86400, (int)ceil((float)$m[1])));
                        }
                    }
                }
                $authLike = $status === 401 || $status === 403 || ($status === 400 && preg_match('/api[ _-]?key/i', $pm));
                $kind = $authLike ? 'auth' : match ($status) { 429 => 'rate_limited', 503 => 'service_unavailable', default => 'unavailable' };
                throw new ProviderException($kind,
                    ($authLike ? 'chiave non valida o non autorizzata' : "risposta HTTP $status") . ($pm !== '' ? " — $pm" : ''), $status, $retryAfter);
            }
            $json = json_decode((string)$raw, true);
            return ['status' => $status, 'json' => is_array($json) ? $json : null];
        }
    }

    /** Toglie dai messaggi qualunque valore di intestazione (chiavi API) e le sequenze simili a chiavi. */
    private static function redact(string $msg, array $headers): string
    {
        foreach ($headers as $v) {
            $v = preg_replace('/^Bearer\s+/i', '', (string)$v) ?? '';
            if (strlen($v) >= 8) { $msg = str_replace($v, '[chiave]', $msg); }
        }
        return preg_replace('/\b(?:AIza[0-9A-Za-z_\-]{20,}|sk-[0-9A-Za-z_\-]{16,})\b/', '[chiave]', $msg) ?? $msg;
    }
}
