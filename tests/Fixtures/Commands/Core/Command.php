<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Core;

/**
 * @template TOptions of array<string, mixed>
 */
abstract class Command
{
    /**
     * @var TOptions
     */
    protected array $options;

    /**
     * @param TOptions $options
     */
    public function __construct(array $options)
    {
        $this->options = $options;
    }

    /**
     * @return TOptions
     */
    public function getOptions(): array
    {
        return $this->options;
    }
}
