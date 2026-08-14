<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class BaseShiftedMethodService
{
    /**
     * Parent defines positional order: $id, $name, $options
     *
     * @param positive-int $id
     * @param non-empty-string $name
     * @param array{active: bool} $options
     */
    public function updateUser(int $id, string $name, array $options = []): bool
    {
        return true;
    }

    /**
     * Static method with positional order: $batch, $format
     *
     * @param list<positive-int> $batch
     * @param non-empty-string $format
     */
    public static function processBatch(array $batch, string $format): bool
    {
        return true;
    }
}
