<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * Middle class passing generic placeholder TElement up to RootGenericBag<TElement>
 *
 * @template TElement of object
 *
 * @extends RootGenericBag<TElement>
 */
abstract class MidGenericBag extends RootGenericBag
{
}
