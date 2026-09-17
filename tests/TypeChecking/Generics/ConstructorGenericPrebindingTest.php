<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\TypePHP;

class ConstructorPrebindAnimal
{
}

class ConstructorPrebindDog extends ConstructorPrebindAnimal
{
}

class ConstructorPrebindCat extends ConstructorPrebindAnimal
{
}

class ConstructorPrebindCar
{
}

/**
 * @template T
 */
class ConstructorPrebindBox
{
    /**
     * @param T[] $content
     */
    public function __construct(public array $content)
    {
    }

    /**
     * @return T[]
     */
    public function getContent(): array
    {
        return $this->content;
    }
}

describe('Constructor Generic Pre-binding with Inline @var Annotation', function () {
    test('prebinds generic template to instance before constructor executes and rejects invalid items', function () {
        expect(function () {
            /** @var ConstructorPrebindBox<ConstructorPrebindAnimal> $box */
            $box = new ConstructorPrebindBox([1, 2, '3']);
        })->toThrow(
            TypeError::class,
            'Argument $content[0] (template T = TypePHP\Tests\TypeChecking\Generics\ConstructorPrebindAnimal) must be of type TypePHP\Tests\TypeChecking\Generics\ConstructorPrebindAnimal'
        );
    });

    test('accepts valid items matching pre-bound template in constructor', function () {
        $dog = new ConstructorPrebindDog();
        $cat = new ConstructorPrebindCat();

        /** @var ConstructorPrebindBox<ConstructorPrebindAnimal> $box */
        $box = new ConstructorPrebindBox([$dog, $cat]);

        expect($box->getContent())->toHaveCount(2)
            ->and($box->getContent()[0])->toBe($dog)
            ->and($box->getContent()[1])->toBe($cat)
            ->and(TypePHP::getGenericType($box))->toBe(ConstructorPrebindAnimal::class)
        ;
    });

    test('rejects items violating pre-bound template in constructor even if first item is a valid subtype', function () {
        $dog = new ConstructorPrebindDog();
        $car = new ConstructorPrebindCar();

        expect(function () use ($dog, $car) {
            /** @var ConstructorPrebindBox<ConstructorPrebindAnimal> $box */
            $box = new ConstructorPrebindBox([$dog, $car]);
        })->toThrow(
            TypeError::class,
            'Argument $content[1] (template T = TypePHP\Tests\TypeChecking\Generics\ConstructorPrebindAnimal) must be of type TypePHP\Tests\TypeChecking\Generics\ConstructorPrebindAnimal'
        );
    });
});
