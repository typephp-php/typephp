<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

interface ShiftedTraitInterface
{
    /**
     * Interface contract: $code (positive-int), $token (non-empty-string)
     *
     * @param positive-int $code
     * @param non-empty-string $token
     */
    public function runAction(int $code, string $token): bool;
}
