<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Shopware\Exception;

class TableHelperExceptionFixture extends BaseShopwareExceptionFixture
{
    public function __construct(
        string $message,
        ?\Throwable $previousException = null
    ) {
        parent::__construct($message, [], $previousException);
    }
}
