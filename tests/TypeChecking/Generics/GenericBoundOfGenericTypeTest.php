<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

// =========================================================================
// Domain Model Fixtures
// =========================================================================

class CovariantBoundModel
{
}

class CovariantBoundUser extends CovariantBoundModel
{
    public function __construct(public string $name = 'Alice')
    {
    }
}

class CovariantBoundAdmin extends CovariantBoundUser
{
}

class CovariantBoundProduct
{
    public function __construct(public string $sku = 'SKU-001')
    {
    }
}

/**
 * Covariant Collection: @template-covariant T
 *
 * @template-covariant T
 */
class CovariantBoundCollection
{
    /**
     * @var array<int, T>
     */
    public array $items = [];

    /**
     * @param array<int, T> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    public function count(): int
    {
        return \count($this->items);
    }
}

/**
 * Invariant Container: standard @template T
 *
 * @template T
 */
class InvariantBoundBox
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

/**
 * Contravariant Consumer: @template-contravariant T
 *
 * @template-contravariant T
 */
class ContravariantBoundConsumer
{
    /**
     * @param T $item
     */
    public function consume(mixed $item): void
    {
    }
}

/**
 * Bound: Covariant collection of Model
 *
 * @template T of CovariantBoundCollection<CovariantBoundModel>
 */
class CovariantBatchProcessor
{
    /**
     * @param T $collection
     */
    public function __construct(public mixed $collection)
    {
    }

    public function process(): int
    {
        return $this->collection->count();
    }
}

/**
 * Bound: Invariant box of Model
 *
 * @template T of InvariantBoundBox<CovariantBoundModel>
 */
class InvariantBatchProcessor
{
    /**
     * @param T $box
     */
    public function __construct(public mixed $box)
    {
    }
}

/**
 * Bound: Invariant box with explicit use-site covariance (<covariant Model>)
 *
 * @template T of InvariantBoundBox<covariant CovariantBoundModel>
 */
class UseSiteCovariantBatchProcessor
{
    /**
     * @param T $box
     */
    public function __construct(public mixed $box)
    {
    }
}

/**
 * Bound: Contravariant consumer of User
 *
 * @template T of ContravariantBoundConsumer<CovariantBoundUser>
 */
class ContravariantBatchProcessor
{
    /**
     * @param T $consumer
     */
    public function __construct(public mixed $consumer)
    {
    }
}

/**
 * Standalone function with generic bound of covariant generic type
 *
 * @template T of CovariantBoundCollection<CovariantBoundModel>
 *
 * @param T $collection
 *
 * @return T
 */
function processCovariantCollection(mixed $collection): mixed
{
    return $collection;
}

/**
 * Standalone function with 2-level nested generic bound
 *
 * @template T of CovariantBoundCollection<CovariantBoundCollection<CovariantBoundModel>>
 *
 * @param T $nested
 *
 * @return T
 */
function processNestedCovariantCollection(mixed $nested): mixed
{
    return $nested;
}

describe('Generic Bounds of Another Generic Type & Variance Resolution', function () {
    describe('1. Declaration-Site Covariance in Generic Bounds (@template T of Collection<Model>)', function () {
        test('accepts Collection<User> because Collection is @template-covariant and User extends Model', function () {
            $userCollection = new CovariantBoundCollection([
                new CovariantBoundUser('Alice'),
                new CovariantBoundUser('Bob'),
            ]);

            /** @var CovariantBatchProcessor<CovariantBoundCollection<CovariantBoundUser>> $processor */
            $processor = new CovariantBatchProcessor($userCollection);

            expect($processor->process())->toBe(2);
        });

        test('accepts multi-level child class Collection<Admin> (Admin extends User extends Model)', function () {
            $adminCollection = new CovariantBoundCollection([
                new CovariantBoundAdmin('SuperAdmin'),
            ]);

            /** @var CovariantBatchProcessor<CovariantBoundCollection<CovariantBoundAdmin>> $processor */
            $processor = new CovariantBatchProcessor($adminCollection);

            expect($processor->process())->toBe(1);
        });

        test('accepts exact type match Collection<Model>', function () {
            $modelCollection = new CovariantBoundCollection();

            /** @var CovariantBatchProcessor<CovariantBoundCollection<CovariantBoundModel>> $processor */
            $processor = new CovariantBatchProcessor($modelCollection);

            expect($processor->process())->toBe(0);
        });

        test('rejects Collection<Product> because Product does not extend Model', function () {
            $productCollection = new CovariantBoundCollection([
                new CovariantBoundProduct('SKU-100'),
            ]);

            expect(function () use ($productCollection) {
                /** @var CovariantBatchProcessor<CovariantBoundCollection<CovariantBoundProduct>> $processor */
                $processor = new CovariantBatchProcessor($productCollection);
            })->toThrow(TypeError::class, 'expects');
        });

        test('rejects non-Collection types (e.g. array) for template bounded by Collection<Model>', function () {
            expect(function () {
                /** @var CovariantBatchProcessor<array> $processor */
                $processor = new CovariantBatchProcessor([]);
            })->toThrow(TypeError::class, 'must be an object of type');
        });
    });

    describe('2. Declaration-Site Invariance in Generic Bounds (@template T of InvariantBox<Model>)', function () {
        test('accepts exact InvariantBox<Model>', function () {
            $model = new CovariantBoundModel();
            $box = new InvariantBoundBox($model);

            /** @var InvariantBatchProcessor<InvariantBoundBox<CovariantBoundModel>> $processor */
            $processor = new InvariantBatchProcessor($box);

            expect($processor->box)->toBe($box);
        });

        test('rejects InvariantBox<User> because InvariantBox is invariant in T even though User extends Model', function () {
            $user = new CovariantBoundUser('Alice');
            $box = new InvariantBoundBox($user);

            expect(function () use ($box) {
                /** @var InvariantBatchProcessor<InvariantBoundBox<CovariantBoundUser>> $processor */
                $processor = new InvariantBatchProcessor($box);
            })->toThrow(TypeError::class, 'expects');
        });
    });

    describe('3. Use-Site Covariance Overriding Invariant Classes (<covariant Model>)', function () {
        test('accepts InvariantBox<User> when bound explicitly specifies use-site covariance', function () {
            $user = new CovariantBoundUser('Alice');
            $box = new InvariantBoundBox($user);

            /** @var UseSiteCovariantBatchProcessor<InvariantBoundBox<CovariantBoundUser>> $processor */
            $processor = new UseSiteCovariantBatchProcessor($box);

            expect($processor->box->item)->toBe($user);
        });

        test('rejects InvariantBox<Product> under use-site covariance because Product is not a Model', function () {
            $product = new CovariantBoundProduct('SKU-999');
            $box = new InvariantBoundBox($product);

            expect(function () use ($box) {
                /** @var UseSiteCovariantBatchProcessor<InvariantBoundBox<CovariantBoundProduct>> $processor */
                $processor = new UseSiteCovariantBatchProcessor($box);
            })->toThrow(TypeError::class, 'expects');
        });
    });

    describe('4. Declaration-Site Contravariance in Generic Bounds (@template T of Consumer<User>)', function () {
        test('accepts Consumer<Model> because Consumer is @template-contravariant and Model is a supertype of User', function () {
            $consumer = new ContravariantBoundConsumer();

            /** @var ContravariantBatchProcessor<ContravariantBoundConsumer<CovariantBoundModel>> $processor */
            $processor = new ContravariantBatchProcessor($consumer);

            expect($processor->consumer)->toBe($consumer);
        });

        test('accepts exact match Consumer<User>', function () {
            $consumer = new ContravariantBoundConsumer();

            /** @var ContravariantBatchProcessor<ContravariantBoundConsumer<CovariantBoundUser>> $processor */
            $processor = new ContravariantBatchProcessor($consumer);

            expect($processor->consumer)->toBe($consumer);
        });

        test('rejects Consumer<Admin> under contravariance because Admin is a subtype (narrower), not a supertype', function () {
            $consumer = new ContravariantBoundConsumer();

            expect(function () use ($consumer) {
                /** @var ContravariantBatchProcessor<ContravariantBoundConsumer<CovariantBoundAdmin>> $processor */
                $processor = new ContravariantBatchProcessor($consumer);
            })->toThrow(TypeError::class);
        });

        test('rejects Consumer<Product> because Product is completely unrelated to User', function () {
            $consumer = new ContravariantBoundConsumer();

            expect(function () use ($consumer) {
                /** @var ContravariantBatchProcessor<ContravariantBoundConsumer<CovariantBoundProduct>> $processor */
                $processor = new ContravariantBatchProcessor($consumer);
            })->toThrow(TypeError::class);
        });
    });

    describe('5. Standalone Generic Functions with Covariant Generic Upper Bounds', function () {
        test('infers and accepts Collection<User> on standalone function with @template T of Collection<Model>', function () {
            $userCollection = new CovariantBoundCollection([
                new CovariantBoundUser('Alice'),
            ]);

            $result = processCovariantCollection($userCollection);

            expect($result)->toBe($userCollection);
        });

        test('rejects Collection<Product> on standalone function with @template T of Collection<Model>', function () {
            $productCollection = new CovariantBoundCollection([
                new CovariantBoundProduct('SKU-100'),
            ]);

            expect(fn () => processCovariantCollection($productCollection))
                ->toThrow(TypeError::class, 'expects')
            ;
        });
    });

    describe('6. Multi-Level Nested Generic Bounds (Collection<Collection<Model>>)', function () {
        test('accepts 2-level nested covariant collection Collection<Collection<User>>', function () {
            $inner = new CovariantBoundCollection([new CovariantBoundUser('Alice')]);
            $nested = new CovariantBoundCollection([$inner]);

            $result = processNestedCovariantCollection($nested);

            expect($result)->toBe($nested);
        });

        test('rejects 2-level nested collection when innermost type violates Model bound', function () {
            $inner = new CovariantBoundCollection([new CovariantBoundProduct('SKU-100')]);
            $nested = new CovariantBoundCollection([$inner]);

            expect(fn () => processNestedCovariantCollection($nested))
                ->toThrow(TypeError::class, 'expects')
            ;
        });
    });
});
