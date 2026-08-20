<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Events;

/**
 * @template TClass
 */
class TestHybridGenericContainer
{
    /**
     * @param TClass $item
     */
    public function __construct(public mixed $item)
    {
    }

    /**
     * Method-level generic template TMethod
     *
     * @template TMethod of object
     *
     * @param TMethod $output
     *
     * @return TMethod
     */
    public function convert(object $output): object
    {
        return $output;
    }
}