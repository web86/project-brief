<?php

namespace App\Exceptions;

use RuntimeException;

class AccountSecurityFailure extends RuntimeException
{
    public function __construct(public readonly string $diagnosticCode, public readonly int $status = 422)
    {
        parent::__construct($diagnosticCode);
    }
}
