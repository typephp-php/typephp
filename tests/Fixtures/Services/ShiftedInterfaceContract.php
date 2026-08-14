<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

interface ShiftedInterfaceContract
{
    /**
     * @param positive-int $code
     * @param non-empty-string $token
     */
    public function execute(int $code, string $token): bool;
}