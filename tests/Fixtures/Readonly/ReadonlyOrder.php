<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Readonly;

class ReadonlyOrder
{
    /**
     * Promoted readonly properties with @param docblock
     *
     * @param positive-int $orderId
     * @param non-empty-string $sku
     * @param int<1, 100> $quantity
     */
    public function __construct(
        public readonly int $orderId,
        public readonly string $sku,
        public readonly int $quantity = 1
    ) {
    }
}