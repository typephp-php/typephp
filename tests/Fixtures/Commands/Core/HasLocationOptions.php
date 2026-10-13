<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Core;

/**
 * @template TOptions of array<string, mixed>
 */
trait HasLocationOptions
{
    /**
     * @var TOptions|null
     */
    public ?array $savedOptions = null;

    /**
     * @param TOptions $options
     *
     * @return TOptions
     */
    public function setLocationOptions(array $options): array
    {
        $this->savedOptions = $options;

        return $options;
    }
}
