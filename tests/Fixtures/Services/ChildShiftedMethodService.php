<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class ChildShiftedMethodService extends BaseShiftedMethodService
{
    /**
     * Child renames $id -> $userId, $name -> $userName, $options -> $userOptions
     */
    public function updateUser(int $userId, string $userName, array $userOptions = []): bool
    {
        return parent::updateUser($userId, $userName, $userOptions);
    }

    /**
     * Static method renames $batch -> $itemBatch, $format -> $outputFormat, and adds optional $notify = false at Index 2
     *
     * @param bool $notify
     */
    public static function processBatch(array $itemBatch, string $outputFormat, bool $notify = false): bool
    {
        return parent::processBatch($itemBatch, $outputFormat);
    }
}
