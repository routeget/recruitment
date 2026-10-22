<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class EntraSsoException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 403,
    ) {
        parent::__construct($message, $httpStatus);
    }
}
