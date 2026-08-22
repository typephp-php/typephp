<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

/**
 * @template TElement of object
 *
 * @extends ShopwareCollection<TElement, string>
 */
class ShopwareEntityCollection extends ShopwareCollection
{
    /**
     * @param iterable<TElement> $elements
     */
    public function __construct(iterable $elements = [])
    {
        foreach ($elements as $key => $element) {
            $this->validateType($element);
            $keyStr = \is_string($key) ? $key : ('id_' . $key);
            $this->elements[$keyStr] = $element;
        }
    }
}
