<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Forwarding;

/**
 * Notice the extra leading template TSelf that shifts T and U by +1
 *
 * @template TSelf of AbstractBox
 * @template T of ItemBase
 * @template U of ItemBase
 *
 * @implements BoxInterface<T, U>
 */
abstract class AbstractBox implements BoxInterface
{
}
