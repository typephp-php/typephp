<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Collections\BoundedRepository;
use TypePHP\Tests\Fixtures\Collections\CovariantProducer;
use TypePHP\Tests\Fixtures\Collections\DoctrineCollection;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Domain\User;
use TypePHP\TypePHP;

/**
 * Function with broad @param mixed, but stricter @phpstan-param positive-int
 *
 * @param mixed $id
 *
 * @phpstan-param positive-int $id
 */
function testPhpstanParamPriority(mixed $id): bool
{
    return true;
}

/**
 * Function with broad @param mixed, but stricter @psalm-param non-empty-string
 *
 * @param mixed $code
 *
 * @psalm-param non-empty-string $code
 */
function testPsalmParamPriority(mixed $code): bool
{
    return true;
}

/**
 * Function with broad @return mixed, but stricter @phpstan-return list<positive-int>
 *
 * @return mixed
 *
 * @phpstan-return list<positive-int>
 */
function testPhpstanReturnPriority(mixed $val): mixed
{
    return $val;
}

/**
 * Function with broad @return mixed, but stricter @psalm-return array{id: positive-int, name: non-empty-string}
 *
 * @return mixed
 *
 * @psalm-return array{id: positive-int, name: non-empty-string}
 */
function testPsalmReturnPriority(mixed $val): mixed
{
    return $val;
}

/**
 * @param CovariantProducer<Animal> $producer
 */
function handleCovariantProducer(CovariantProducer $producer): mixed
{
    return $producer->get();
}

describe('Tooling Annotation Priorities (@phpstan-* and @psalm-*)', function () {
    describe('Parameter Contract Priority (@phpstan-param & @psalm-param)', function () {
        test('enforces @phpstan-param positive-int over broad @param mixed', function () {
            expect(testPhpstanParamPriority(42))->toBeTrue();

            expect(fn () => testPhpstanParamPriority(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('enforces @psalm-param non-empty-string over broad @param mixed', function () {
            expect(testPsalmParamPriority('valid_code'))->toBeTrue();

            expect(fn () => testPsalmParamPriority(''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });
    });

    describe('Return Contract Priority (@phpstan-return & @psalm-return)', function () {
        test('enforces @phpstan-return list<positive-int> over broad @return mixed', function () {
            expect(testPhpstanReturnPriority([10, 20, 30]))->toBe([10, 20, 30]);

            expect(fn () => testPhpstanReturnPriority([10, -5, 30]))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('enforces @psalm-return array shape over broad @return mixed', function () {
            expect(testPsalmReturnPriority(['id' => 10, 'name' => 'Alice']))->toBe(['id' => 10, 'name' => 'Alice']);

            expect(fn () => testPsalmReturnPriority(['id' => -10, 'name' => 'Alice']))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect(fn () => testPsalmReturnPriority(['id' => 10, 'name' => '']))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });
    });

    describe('Inline Variable Priority (@phpstan-var & @psalm-var)', function () {
        test('enforces @phpstan-var positive-int over broad @var mixed on local assignment', function () {
            /**
             * @var mixed $score
             *
             * @phpstan-var positive-int $score
             */
            $score = 100;
            expect($score)->toBe(100);

            expect(function () use (&$score) {
                $score = -50;
            })->toThrow(TypeError::class, 'positive-int');
        });

        test('enforces @psalm-var non-empty-string over broad @var mixed on local assignment', function () {
            /**
             * @var mixed $tag
             *
             * @psalm-var non-empty-string $tag
             */
            $tag = 'active';
            expect($tag)->toBe('active');

            expect(function () use (&$tag) {
                $tag = '';
            })->toThrow(TypeError::class, 'non-empty-string');
        });
    });

    describe('Inherited Generic Collections with @phpstan-param (Doctrine Collection Pattern)', function () {
        test('enforces inherited generic template T from @phpstan-param on implementing class', function () {
            /** @var DoctrineCollection<Animal> $collection */
            $collection = new DoctrineCollection();

            expect($collection->add(new Dog()))->toBeTrue();

            expect(fn () => $collection->add(new User()))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Animal')
            ;
        });
    });

    describe('@phpstan-template Bounds & Covariance Priorities', function () {
        test('enforces @phpstan-template upper bound Animal when broad @template has no bound', function () {
            $repo = new BoundedRepository(new Dog());
            expect($repo->item)->toBeInstanceOf(Dog::class);

            expect(fn () => new BoundedRepository(new Car()))
                ->toThrow(TypeError::class, 'TypePHP\Tests\Fixtures\Domain\Animal')
            ;
        });

        test('detects and enforces @phpstan-template-covariant variance on instance', function () {
            $producer = new CovariantProducer(new Dog());

            expect(TypePHP::getGenericVariance($producer))->toBe('covariant')
                ->and(handleCovariantProducer($producer))->toBeInstanceOf(Dog::class);
        });
    });
});
