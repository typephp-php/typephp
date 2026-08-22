<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * Child class passing generic placeholder TElement up to MidGenericBag<TElement>
 *
 * @template TElement of object
 *
 * @extends MidGenericBag<TElement>
 */
class ChildGenericBag extends MidGenericBag
{
}
