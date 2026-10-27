<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class SafeExceptionReporter
{
    public function report(Throwable $exception): void
    {
        // SQL bindings, exception messages, request URLs and stack arguments may contain secrets.
        Log::error('application_failure', [
            'diagnostic_id' => (string) Str::uuid(),
            'exception_type' => $exception::class,
            'file' => basename($exception->getFile()),
            'line' => $exception->getLine(),
        ]);
    }
}
