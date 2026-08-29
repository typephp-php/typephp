<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Shopware\Error;

/**
 * Notice: Error is in the SAME namespace (TypePHP\Tests\Fixtures\Shopware\Error\Error)
 * and is NOT the global PHP \Error class!
 */
class ErrorCollection
{
    /**
     * @var list<Error>
     */
    public array $elements = [];

    /**
     * @param iterable<Error> $elements
     */
    public function __construct(iterable $elements = [])
    {
        foreach ($elements as $element) {
            $this->elements[] = $element;
        }
    }
}