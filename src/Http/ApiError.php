<?php
declare(strict_types=1);
namespace Ablet\Http;

final class ApiError extends \RuntimeException
{
    public function __construct(public readonly int $status, string $code, public readonly mixed $detail = null)
    {
        parent::__construct($code);
    }
}

