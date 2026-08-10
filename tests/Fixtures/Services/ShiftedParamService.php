<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class ShiftedParamService extends BaseNamedParamService
{
    /**
     * Child renames $id -> $userId, $name -> $userName, $role -> $userRole
     * and adds an optional parameter $notify
     *
     * @param bool $notify
     */
    public function registerUser(int $userId, string $userName, string $userRole = 'user', bool $notify = false): bool
    {
        return parent::registerUser($userId, $userName, $userRole);
    }
}
