<?php

namespace Modules\SuperPdp\Exceptions;

use RuntimeException;

/**
 * Error returned by the SUPER PDP API (or a network failure, status 0).
 * Retryable when the request may succeed later as is.
 */
class SuperPdpException extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 0, public readonly ?string $body = null)
    {
        parent::__construct($message);
    }

    public function isRetryable(): bool
    {
        return $this->status === 0 || $this->status === 408 || $this->status === 429 || $this->status >= 500;
    }
}
