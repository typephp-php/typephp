<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

trait ShiftedFulfillmentTrait
{
    /**
     * Trait fulfills interface contract with renamed parameters
     */
    public function runAction(int $actionCode, string $actionToken): bool
    {
        return true;
    }
}
