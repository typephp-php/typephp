<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Core;

/**
 * @template TOptions of array<string, mixed>
 */
interface CommandInterface
{
    /**
     * @param TOptions $options
     *
     * @return TOptions
     */
    public function execute(array $options): array;
}
