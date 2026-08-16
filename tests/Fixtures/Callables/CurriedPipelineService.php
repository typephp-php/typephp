<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Callables;

class CurriedPipelineService
{
    /**
     * Higher-order method returning a curried validator function
     *
     * @return callable(positive-int): (callable(non-empty-string): bool)
     */
    public function createValidatorFactory(): callable
    {
        return function (int $minLen): callable {
            return function (string $text) use ($minLen): bool {
                return \strlen($text) >= $minLen;
            };
        };
    }

    /**
     * Higher-order method returning a factory with invalid inner return type
     *
     * @return callable(positive-int): (callable(non-empty-string): bool)
     */
    public function createBadReturnFactory(): callable
    {
        return function (int $minLen): callable {
            return function (string $text): int {
                return 12345; // Returns int instead of bool!
            };
        };
    }
}
