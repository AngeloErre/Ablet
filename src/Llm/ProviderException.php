<?php
declare(strict_types=1);

namespace Ablet\Llm;

/** kind: unavailable | timeout | auth | bad_response | budget | config | cancelled | rate_limited | service_unavailable */
final class ProviderException extends \RuntimeException
{
    public function __construct(public readonly string $kind, string $message = '', public readonly ?int $httpStatus = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message !== '' ? $message : $kind);
    }
}
