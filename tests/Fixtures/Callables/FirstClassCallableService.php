<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Callables;

class FirstClassCallableService
{
    /**
     * Instance method contract
     *
     * @param positive-int $id
     * @param non-empty-string $prefix
     *
     * @return non-empty-string
     */
    public function formatRecord(int $id, string $prefix): string
    {
        return "{$prefix}_{$id}";
    }

    /**
     * Static method contract
     *
     * @param positive-int $code
     *
     * @return non-empty-string
     */
    public static function formatStaticCode(int $code): string
    {
        return "CODE_{$code}";
    }

    /**
     * Method returning invalid return value
     *
     * @param positive-int $id
     *
     * @return non-empty-string
     */
    public function badReturnMethod(int $id): string
    {
        return ''; // Violates non-empty-string!
    }
}