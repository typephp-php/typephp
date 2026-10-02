<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\PropertyHooks;

class AsymmetricPromotedVarOrder
{
    public function __construct(
        /**
         * @var positive-int
         */
        public private(set) int $orderId,
    ) {
    }
}
