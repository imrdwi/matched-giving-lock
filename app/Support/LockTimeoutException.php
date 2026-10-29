<?php

namespace App\Support;

use RuntimeException;

final class LockTimeoutException extends RuntimeException
{
    public function __construct(public readonly string $lockName, int $timeoutSeconds)
    {
        parent::__construct("Could not acquire lock [{$lockName}] within {$timeoutSeconds}s.");
    }
}
