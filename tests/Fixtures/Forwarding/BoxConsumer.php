<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Forwarding;

final class BoxConsumer
{
    /**
     * @template T of ItemBase
     * @template U of ItemBase
     *
     * @param BoxInterface<T, U> $box
     */
    public function __construct(public readonly BoxInterface $box)
    {
    }

    /**
     * @template T of ItemBase
     * @template U of ItemBase
     *
     * @param BoxInterface<T, U> $box
     *
     * @return T
     */
    public function extractFirst(BoxInterface $box): ItemBase
    {
        return new ItemA();
    }

    /**
     * @template T of ItemBase
     * @template U of ItemBase
     *
     * @param BoxInterface<T, U> $box
     *
     * @return U
     */
    public function extractSecond(BoxInterface $box): ItemBase
    {
        return new ItemB();
    }

    /**
     * Method returning invalid type (returns ItemB when T is ItemA)
     *
     * @template T of ItemBase
     * @template U of ItemBase
     *
     * @param BoxInterface<T, U> $box
     *
     * @return T
     */
    public function extractBad(BoxInterface $box): ItemBase
    {
        return new ItemB();
    }

    /**
     * @template T of ItemBase
     * @template U of ItemBase
     *
     * @param BoxInterface<T, U> $box
     *
     * @return BoxInterface<T, U>
     */
    public function passThrough(BoxInterface $box): BoxInterface
    {
        return $box;
    }
}