<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Shopware\Error;

abstract class Error extends \Exception
{
    public function __construct(string $message = '')
    {
        parent::__construct($message);
    }
}
