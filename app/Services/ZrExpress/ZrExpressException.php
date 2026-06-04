<?php

namespace App\Services\ZrExpress;

use RuntimeException;

/**
 * Thrown for any ZR Express integration failure: misconfiguration
 * (missing token), a non-2xx API response, or an unmappable
 * wilaya/commune. Carries an optional structured context payload
 * (HTTP status, response body, …) for logging and admin display.
 */
class ZrExpressException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $context = [],
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
