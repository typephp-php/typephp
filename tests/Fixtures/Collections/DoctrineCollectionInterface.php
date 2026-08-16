<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

/**
 * @template T
 */
interface DoctrineCollectionInterface
{
    /**
     * @param mixed $element
     * @phpstan-param T $element
     */
    public function add(mixed $element): bool;
}