<?php
declare(strict_types=1);
namespace Ablet\Llm;

use Ablet\Support\Database;

/** Attesa condivisa fra richieste della stessa configurazione; nessuna attesa bloccante PHP. */
final class Cooldown
{
    public static function check(int $id): void
    {
        $st = Database::pdo()->prepare("SELECT bucket, CEIL(TIMESTAMPDIFF(MICROSECOND, UTC_TIMESTAMP(3), window_start)/1000000) seconds FROM rate_limits WHERE bucket IN (?,?) AND window_start > UTC_TIMESTAMP(3) ORDER BY window_start DESC LIMIT 1");
        $st->execute(["llm-wait:$id:429", "llm-wait:$id:503"]);
        if ($row = $st->fetch()) {
            $status = str_ends_with($row['bucket'], ':429') ? 429 : 503;
            throw new ProviderException($status === 429 ? 'rate_limited' : 'service_unavailable', 'Attesa temporanea prima del prossimo tentativo.', $status, (int)$row['seconds']);
        }
    }

    public static function record(int $id, ProviderException $e): void
    {
        if (!in_array($e->httpStatus, [429,503], true)) { return; }
        $seconds = max(1, min(86400, $e->retryAfter ?? ($e->httpStatus === 429 ? 60 : 10)));
        Database::pdo()->prepare("INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, TIMESTAMPADD(SECOND, ?, UTC_TIMESTAMP(3)), 0) ON DUPLICATE KEY UPDATE window_start = GREATEST(window_start, VALUES(window_start))")
            ->execute(["llm-wait:$id:{$e->httpStatus}", $seconds]);
    }
}
