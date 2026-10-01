<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\TypePHP;

class AccumulationBird
{
    public function __construct(public string $name = 'Tweety')
    {
    }
}

class AccumulationFish
{
    public function __construct(public string $name = 'Nemo')
    {
    }
}

/**
 * Mutable collection accumulating types via @self-out self<T|TNew>
 *
 * @template T
 */
class MutableAccumulationCollection
{
    /**
     * @var array<int, T>
     */
    public array $items = [];

    /**
     * @template TNew
     *
     * @param TNew $item
     *
     * @self-out self<T|TNew>
     */
    public function push(mixed $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @param T $item
     */
    public function addStrict(mixed $item): void
    {
        $this->items[] = $item;
    }
}

/**
 * Class with redundant union in return type
 */
class DuplicateUnionMethodFixture
{
    /**
     * @template T
     *
     * @param T $a
     * @param T $b
     *
     * @return T|T
     */
    public function duplicateUnion(mixed $a, mixed $b): mixed
    {
        return $a;
    }
}

describe('Union & Intersection Accumulation, Flattening and Deduplication', function () {

    describe('1. Dynamic @self-out Union Accumulation (self<T|TNew>)', function () {
        test('initial state initializes cleanly with single type', function () {
            /** @var MutableAccumulationCollection<Dog> $col */
            $col = new MutableAccumulationCollection();

            expect(TypePHP::getGenericType($col))->toBe(Dog::class);
        });

        test('deduplicates identical type on push without creating redundant (Dog | Dog) union', function () {
            /** @var MutableAccumulationCollection<Dog> $col */
            $col = new MutableAccumulationCollection();

            $col->push(new Dog('Rex'));

            expect(TypePHP::getGenericType($col))->toBe(Dog::class);
        });

        test('accumulates distinct second type into 2-member union (Dog | Cat)', function () {
            /** @var MutableAccumulationCollection<Dog> $col */
            $col = new MutableAccumulationCollection();

            $col->push(new Dog('Rex'));
            $col->push(new Cat('Whiskers'));

            expect(TypePHP::getGenericType($col))->toBe('(' . Dog::class . ' | ' . Cat::class . ')');
        });

        test('flattens 3-member nested union into single-level (Dog | Cat | Bird) without ((A|B)|C) nesting', function () {
            /** @var MutableAccumulationCollection<Dog> $col */
            $col = new MutableAccumulationCollection();

            $col->push(new Dog('Rex'));
            $col->push(new Cat('Whiskers'));
            $col->push(new AccumulationBird('Tweety'));

            $expected = '(' . Dog::class . ' | ' . Cat::class . ' | ' . AccumulationBird::class . ')';
            expect(TypePHP::getGenericType($col))->toBe($expected);
        });

        test('flattens 4-member nested union through sequential pushes', function () {
            /** @var MutableAccumulationCollection<Dog> $col */
            $col = new MutableAccumulationCollection();

            $col->push(new Dog('Rex'));
            $col->push(new Cat('Whiskers'));
            $col->push(new AccumulationBird('Tweety'));
            $col->push(new AccumulationFish('Nemo'));

            $expected = '(' . Dog::class . ' | ' . Cat::class . ' | ' . AccumulationBird::class . ' | ' . AccumulationFish::class . ')';
            expect(TypePHP::getGenericType($col))->toBe($expected);
        });

        test('deduplicates already accumulated types when pushed again later in the sequence', function () {
            /** @var MutableAccumulationCollection<Dog> $col */
            $col = new MutableAccumulationCollection();

            $col->push(new Cat('Whiskers'));
            $col->push(new AccumulationBird('Tweety'));
            // Push Dog again (already present from initial state)
            $col->push(new Dog('Rex'));
            // Push Cat again
            $col->push(new Cat('Shadow'));

            $expected = '(' . Dog::class . ' | ' . Cat::class . ' | ' . AccumulationBird::class . ')';
            expect(TypePHP::getGenericType($col))->toBe($expected);
        });

        test('strictly enforces the accumulated union against invalid additions', function () {
            /** @var MutableAccumulationCollection<Dog> $col */
            $col = new MutableAccumulationCollection();

            $col->push(new Cat('Whiskers'));
            $col->push(new AccumulationBird('Tweety'));

            $col->addStrict(new Dog());
            $col->addStrict(new Cat());
            $col->addStrict(new AccumulationBird());

            expect(\count($col->items))->toBe(5);

            expect(fn () => $col->addStrict(new Car()))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('2. AST Level Normalization Helpers (TemplateSubstitutor)', function () {
        test('normalizeUnion flattens deeply nested UnionTypeNode trees', function () {
            $dog = new IdentifierTypeNode('Dog');
            $cat = new IdentifierTypeNode('Cat');
            $bird = new IdentifierTypeNode('Bird');

            $nested = new UnionTypeNode([
                new UnionTypeNode([$dog, $cat]),
                $bird,
            ]);

            $normalized = TemplateSubstitutor::normalizeUnion([$nested]);

            expect($normalized)->toBeInstanceOf(UnionTypeNode::class)
                ->and((string) $normalized)->toBe('(Dog | Cat | Bird)')
                ->and(\count($normalized->types))->toBe(3)
            ;
        });

        test('normalizeUnion deduplicates identical types', function () {
            $types = [
                new IdentifierTypeNode('Dog'),
                new IdentifierTypeNode('Cat'),
                new IdentifierTypeNode('Dog'),
                new IdentifierTypeNode('Cat'),
                new IdentifierTypeNode('Bird'),
            ];

            $normalized = TemplateSubstitutor::normalizeUnion($types);

            expect($normalized)->toBeInstanceOf(UnionTypeNode::class)
                ->and((string) $normalized)->toBe('(Dog | Cat | Bird)')
                ->and(\count($normalized->types))->toBe(3)
            ;
        });

        test('normalizeUnion unwraps single-element union back to base TypeNode', function () {
            $types = [
                new IdentifierTypeNode('Dog'),
                new IdentifierTypeNode('Dog'),
            ];

            $normalized = TemplateSubstitutor::normalizeUnion($types);

            expect($normalized)->toBeInstanceOf(IdentifierTypeNode::class)
                ->and($normalized->name)->toBe('Dog')
            ;
        });

        test('normalizeUnion simplifies union containing mixed to mixed directly', function () {
            $types = [
                new IdentifierTypeNode('int'),
                new IdentifierTypeNode('string'),
                new IdentifierTypeNode('mixed'),
            ];

            $normalized = TemplateSubstitutor::normalizeUnion($types);

            expect($normalized)->toBeInstanceOf(IdentifierTypeNode::class)
                ->and($normalized->name)->toBe('mixed')
            ;
        });

        test('normalizeIntersection flattens, deduplicates, and eliminates mixed', function () {
            $countable = new IdentifierTypeNode('Countable');
            $arrayAccess = new IdentifierTypeNode('ArrayAccess');
            $mixed = new IdentifierTypeNode('mixed');

            $nested = new IntersectionTypeNode([
                $countable,
                new IntersectionTypeNode([$arrayAccess, $countable]),
                $mixed,
            ]);

            $normalized = TemplateSubstitutor::normalizeIntersection([$nested]);

            expect($normalized)->toBeInstanceOf(IntersectionTypeNode::class)
                ->and((string) $normalized)->toBe('(Countable & ArrayAccess)')
                ->and(\count($normalized->types))->toBe(2)
            ;
        });

        test('normalizeIntersection unwraps single-element intersection to base TypeNode', function () {
            $types = [
                new IdentifierTypeNode('Countable'),
                new IdentifierTypeNode('Countable'),
            ];

            $normalized = TemplateSubstitutor::normalizeIntersection($types);

            expect($normalized)->toBeInstanceOf(IdentifierTypeNode::class)
                ->and($normalized->name)->toBe('Countable')
            ;
        });
    });

    describe('3. Return Type Simplification with Duplicate Union Branches (@return T|T)', function () {
        test('simplifies redundant union return types after template substitution', function () {
            $service = new DuplicateUnionMethodFixture();

            $result = $service->duplicateUnion(100, 200);

            expect($result)->toBe(100);
        });
    });
});
