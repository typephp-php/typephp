<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Trait declaring dynamic @method
 *
 * @phpstan-type RoleUnion 'admin'|'user'
 *
 * @method bool traitMethod(RoleUnion $role)
 */
trait MagicMethodTrait
{
}
