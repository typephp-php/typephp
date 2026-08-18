<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Shopware\Exception;

abstract class BaseShopwareExceptionFixture extends \Exception
{
    /**
     * @param string $message
     * @param array<string, mixed> $parameters
     * @param \Throwable|null $e
     */
    public function __construct(
        string $message,
        array $parameters = [],
        ?\Throwable $e = null
    ) {
        parent::__construct($message, 0, $e);
    }
}