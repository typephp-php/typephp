<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

trait ShiftedLoggerTrait
{
    /**
     * @param positive-int $level
     * @param non-empty-string $message
     */
    public function logEvent(int $level, string $message): bool
    {
        return true;
    }
}
