<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

class HrHandoverGateNotSatisfiedException extends RuntimeException
{
    public function __construct(
        string $message = 'HR handover safety gate is not satisfied.',
        public readonly string $errorCode = 'HR_HANDOVER_GATE_NOT_SATISFIED',
    ) {
        parent::__construct($message, 409);
    }
}
