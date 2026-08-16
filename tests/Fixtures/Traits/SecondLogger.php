<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits;

trait SecondLogger
{
    /**
     * Second logger demands negative integers for level
     *
     * @param negative-int $level
     * @param string $message
     */
    public function log(int $level, string $message): string
    {
        return "second: {$level} - {$message}";
    }
}
