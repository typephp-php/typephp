<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class MissingConstKeyContainer
{
    /**
     * @param array{self::NON_EXISTENT_KEY: positive-int} $payload
     */
    public function process(array $payload): bool
    {
        return true;
    }
}
