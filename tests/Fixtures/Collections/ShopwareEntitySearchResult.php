<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

/**
 * @template TElement of object
 *
 * @extends ShopwareEntityCollection<TElement>
 */
class ShopwareEntitySearchResult extends ShopwareEntityCollection
{
    public function __construct(?ShopwareEntityCollection $entities = null)
    {
        parent::__construct($entities ?? []);
    }
}
