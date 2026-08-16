<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits;

trait FirstLogger
{
    /**
     * First logger demands positive integers for level
     *
     * @param positive-int $level
     * @param non-empty-string $message
     */
    public function log(int $level, string $message): string
    {
        return "first: {$level} - {$message}";
    }
}
