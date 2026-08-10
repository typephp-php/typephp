<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class BaseNamedParamService
{
    /**
     * Parent defines positional order: $id, $name, $role
     *
     * @param positive-int $id
     * @param non-empty-string $name
     * @param 'admin'|'user' $role
     */
    public function registerUser(int $id, string $name, string $role): bool
    {
        return true;
    }
}
