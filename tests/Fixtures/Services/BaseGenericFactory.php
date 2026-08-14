<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

use stdClass;
use TypePHP\Tests\Fixtures\Generics\Producer;

/**
 * @template T
 */
abstract class BaseGenericFactory
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }

    /**
     * Static factory creating an instance of static<TValue>
     *
     * @template TValue
     *
     * @param TValue $value
     *
     * @return static<TValue>
     */
    public static function of(mixed $value): static
    {
        return new static($value);
    }

    /**
     * Static factory returning wrong item violating generic T
     *
     * @template TValue
     *
     * @param TValue $value
     *
     * @return static<TValue>
     */
    public static function ofBadItem(mixed $value): static
    {
        return new static(new stdClass());
    }

    /**
     * Method returning Producer holding static instance: Producer<static<T>>
     *
     * @return Producer<static<T>>
     */
    public function toProducer(): Producer
    {
        return new Producer($this);
    }

    /**
     * Method returning Producer holding sibling instance
     *
     * @return Producer<static<T>>
     */
    public function toBadProducer(): Producer
    {
        return new Producer(new AdminGenericFactory($this->item));
    }
}
