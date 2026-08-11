<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class ConstKeyContainer
{
    public const KEY_ID = 'user_id';
    public const KEY_ROLE = 'user_role';

    /**
     * @param array{self::KEY_ID: positive-int, self::KEY_ROLE: 'admin'|'user'} $payload
     */
    public function process(array $payload): bool
    {
        return true;
    }
}
