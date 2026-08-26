<?php

declare(strict_types=1);

class ChainedField
{
    public function __construct(public string $name = 'id')
    {
    }
}

class ChainedAssociationField extends ChainedField
{
    public function __construct(public string $name = 'orders', public string $target = 'Order')
    {
        parent::__construct($name);
    }
}

class ChainedCar
{
}

/**
 * Generic base collection with class template TElement
 *
 * @template TElement
 */
abstract class ChainedBaseCollection
{
    /**
     * @var array<int, TElement>
     */
    public array $elements = [];

    public function add(mixed $element): void
    {
        $this->elements[] = $element;
    }

    /**
     * Method template T bounded by class template TElement (like Collection::firstWhere)
     *
     * @template T of TElement
     *
     * @param Closure(T): bool $closure
     *
     * @return TElement|null
     */
    public function firstWhere(Closure $closure): mixed
    {
        foreach ($this->elements as $element) {
            if ($closure($element)) {
                return $element;
            }
        }

        return null;
    }
}

/**
 * Concrete collection binding TElement to ChainedField
 *
 * @extends ChainedBaseCollection<ChainedField>
 */
class ChainedFieldCollection extends ChainedBaseCollection
{
}

/**
 * Unparameterized collection where TElement is unbound (mixed)
 */
class ChainedUnparameterizedCollection extends ChainedBaseCollection
{
}

describe('Chained Template Bounds (@template T of TElement in Closures)', function () {
    test('resolves chained method template T bounded by class template TElement in closure parameter', function () {
        $fields = new ChainedFieldCollection();
        $assocField = new ChainedAssociationField('customer');
        $fields->add($assocField);

        $result = $fields->firstWhere(function (ChainedField $field): bool {
            return $field->name === 'customer';
        });

        expect($result)->toBe($assocField);
    });

    test('falls back chained template T to mixed when class template TElement is unbound', function () {
        $collection = new ChainedUnparameterizedCollection();
        $collection->add(new ChainedCar());

        $result = $collection->firstWhere(function ($item): bool {
            return true;
        });

        expect($result)->toBeInstanceOf(ChainedCar::class);
    });
});
