<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class ChildShiftedOopService extends BaseShiftedAbstractService
{
    use ShiftedLoggerTrait;

    /**
     * Implements Interface method with renamed parameters: $code -> $statusCode, $token -> $authToken
     */
    public function execute(int $statusCode, string $authToken): bool
    {
        return true;
    }

    /**
     * Implements Abstract method with renamed parameter: $items -> $itemList
     */
    public function processItems(array $itemList): bool
    {
        return true;
    }

    /**
     * Overrides Trait method with renamed parameters: $level -> $logLevel, $message -> $logMessage
     */
    public function logEvent(int $logLevel, string $logMessage): bool
    {
        return true;
    }
}
