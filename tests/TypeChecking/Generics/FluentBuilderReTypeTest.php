<?php

declare(strict_types=1);

use TypePHP\TypePHP;

class FixtureAuthorModel
{
    public function __construct(public string $name = 'Brent')
    {
    }
}

/**
 * Fixture reproducing Tempest ObjectFactory<ClassType> fluent builder
 *
 * @template ClassType
 */
class FixtureObjectFactory
{
    public bool $isCollection = false;

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return self<T>
     */
    public function forClass(string $class): self
    {
        return $this;
    }

    /**
     * Re-types ClassType on $this to ClassType[]
     *
     * @return self<ClassType[]>
     */
    public function collection(): self
    {
        $this->isCollection = true;

        return $this;
    }

    /**
     * @return ClassType
     */
    public function from(array $data): mixed
    {
        return $this->isCollection
            ? [new FixtureAuthorModel('Brent'), new FixtureAuthorModel('Roman')]
            : new FixtureAuthorModel('Brent');
    }
}

describe('Fluent Builder Generic Re-Typing (Tempest ObjectFactory pattern)', function () {
    test('allows fluent methods returning $this to re-type class template to an array of objects', function () {
        $factory = new FixtureObjectFactory();

        $factory->forClass(FixtureAuthorModel::class);

        $collectionFactory = $factory->collection();

        expect($collectionFactory)->toBe($factory)
            ->and(TypePHP::getGenericType($factory))->toBe(FixtureAuthorModel::class . '[]')
        ;

        $result = $factory->from([['name' => 'Brent'], ['name' => 'Roman']]);

        expect($result)->toBeArray()
            ->and($result[0])->toBeInstanceOf(FixtureAuthorModel::class)
        ;
    });
});
