<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class HookedUser
{
    /**
     * Asymmetric visibility property with @var constraint
     *
     * @var positive-int
     */
    public private(set) int $id = 10;

    /**
     * Property hook with asymmetric visibility and @var constraint
     *
     * @var non-empty-string
     */
    public protected(set) string $username {
        get => $this->_username;
        set => $this->_username = trim($value);
    }

    private string $_username = 'Alice';

    public function updateProfile(int $newId, string $newUsername): void
    {
        $this->id = $newId;
        $this->username = $newUsername;
    }
}
