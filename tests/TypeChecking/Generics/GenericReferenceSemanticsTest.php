<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

final class RefUser
{
    public function __construct(public string $name)
    {
    }
}

final class RefProduct
{
    public function __construct(public string $sku)
    {
    }
}

/**
 * @template T
 */
final class RefBox
{
    /**
     * @var T
     */
    public mixed $item;

    /**
     * @param T $item
     */
    public function __construct(mixed $item)
    {
        $this->item = $item;
    }
}

final class RefTypedHolder
{
    /**
     * @var positive-int
     */
    public int $score = 10;

    /**
     * @var list<positive-int>
     */
    public array $numbers = [1, 2, 3];

    /**
     * @var positive-int
     */
    public static int $limit = 100;
}

final class RefBoxFactory
{
    public int $calls = 0;

    /**
     * @var RefBox<RefUser>
     */
    public RefBox $box;

    public function __construct()
    {
        /** @var RefBox<RefUser> $b */
        $b = new RefBox(new RefUser('FactoryUser'));
        $this->box = $b;
    }

    /**
     * @return RefBox<RefUser>
     */
    public function next(): RefBox
    {
        $this->calls++;

        return $this->box;
    }
}

/**
 * @param RefBox<RefUser> $box
 */
function refCorruptWithParamDoc(RefBox $box): void
{
    $ref = &$box->item;
    $ref = new RefProduct('SKU-999');
}

/**
 * @param RefBox<RefUser> $box
 */
function refUpdateValidWithParamDoc(RefBox $box): void
{
    $ref = &$box->item;
    $ref = new RefUser('Alice Updated');
}

function refCorruptWithoutParamDoc(RefBox $box): void
{
    $ref = &$box->item;
    $ref = new RefProduct('SKU-888');
}

function refUpdateValidWithoutParamDoc(RefBox $box): void
{
    $ref = &$box->item;
    $ref = new RefUser('Bob Updated');
}

describe('Generic & Typed Reference Semantics', function () {
    describe('1. Local Reference Alias to Generic Object Property ($ref = &$box->item)', function () {
        test('rejects invalid mutation through $ref with concrete bound type (RefUser, not raw T) when @param doc is present', function () {
            /** @var RefBox<RefUser> $userBox */
            $userBox = new RefBox(new RefUser('Alice'));

            expect(fn () => refCorruptWithParamDoc($userBox))
                ->toThrow(TypeError::class, RefUser::class)
            ;

            expect($userBox->item)->toBeInstanceOf(RefUser::class)
                ->and($userBox->item->name)->toBe('Alice')
            ;
        });

        test('allows valid RefUser mutation through $ref inside function with @param Box<RefUser>', function () {
            /** @var RefBox<RefUser> $userBox */
            $userBox = new RefBox(new RefUser('Alice'));

            refUpdateValidWithParamDoc($userBox);

            expect($userBox->item)->toBeInstanceOf(RefUser::class)
                ->and($userBox->item->name)->toBe('Alice Updated')
            ;
        });

        test('enforces live WeakMap generic binding through $ref even without @param Box<RefUser> docblock', function () {
            /** @var RefBox<RefUser> $userBox */
            $userBox = new RefBox(new RefUser('Bob'));

            expect(fn () => refCorruptWithoutParamDoc($userBox))
                ->toThrow(TypeError::class, RefUser::class)
            ;

            refUpdateValidWithoutParamDoc($userBox);

            expect($userBox->item)->toBeInstanceOf(RefUser::class)
                ->and($userBox->item->name)->toBe('Bob Updated')
            ;
        });

        test('releases property alias on unset($ref) and updates target on rebinding $ref = &$otherBox->item', function () {
            /** @var RefBox<RefUser> $userBox */
            $userBox = new RefBox(new RefUser('Charlie'));

            /** @var RefBox<RefProduct> $productBox */
            $productBox = new RefBox(new RefProduct('SKU-1'));

            $ref = &$userBox->item;
            $ref = new RefUser('Charlie Updated');
            expect($userBox->item->name)->toBe('Charlie Updated');

            // Rebind $ref to $productBox->item
            $ref = &$productBox->item;
            $ref = new RefProduct('SKU-2');
            expect($productBox->item->sku)->toBe('SKU-2')
                ->and($userBox->item->name)->toBe('Charlie Updated')
            ;

            // Break reference with unset($ref): reassigning $ref should no longer enforce $productBox->item
            unset($ref);
            $ref = new RefUser('Detached');
            expect($ref)->toBeInstanceOf(RefUser::class)
                ->and($productBox->item->sku)->toBe('SKU-2')
            ;
        });
    });

    describe('2. Assign-by-Reference Across Properties ($a->item = &$b->item)', function () {
        test('rejects binding Box<RefUser>::$item by reference to Box<RefProduct>::$item and preserves original value', function () {
            /** @var RefBox<RefUser> $userBox */
            $userBox = new RefBox(new RefUser('Bob'));

            /** @var RefBox<RefProduct> $productBox */
            $productBox = new RefBox(new RefProduct('SKU-1'));

            expect(function () use ($userBox, $productBox): void {
                $userBox->item = &$productBox->item;
            })->toThrow(TypeError::class, RefUser::class);

            expect($userBox->item)->toBeInstanceOf(RefUser::class)
                ->and($userBox->item->name)->toBe('Bob')
            ;
        });

        test('allows binding compatible Box<RefUser>::$item by reference and preserves reference semantics', function () {
            /** @var RefBox<RefUser> $boxA */
            $boxA = new RefBox(new RefUser('Alice'));

            /** @var RefBox<RefUser> $boxB */
            $boxB = new RefBox(new RefUser('Bob'));

            $boxA->item = &$boxB->item;
            expect($boxA->item->name)->toBe('Bob');

            $boxB->item = new RefUser('Shared');
            expect($boxA->item->name)->toBe('Shared');
        });

        test('rejects assign-by-reference violating non-generic instance and static property contracts', function () {
            $holder = new RefTypedHolder();
            $invalid = -5;

            expect(function () use ($holder, &$invalid): void {
                $holder->score = &$invalid;
            })->toThrow(TypeError::class, 'positive-int');
            expect($holder->score)->toBe(10);

            RefTypedHolder::$limit = 100;
            $invalidStatic = -10;

            expect(function () use (&$invalidStatic): void {
                RefTypedHolder::$limit = &$invalidStatic;
            })->toThrow(TypeError::class, 'positive-int');
            expect(RefTypedHolder::$limit)->toBe(100);
        });
    });

    describe('3. Assign-by-Reference to Typed Variables, Array Shapes & Property Dimensions', function () {
        test('validates assign-by-reference against existing typed local variable contract', function () {
            /** @var positive-int $count */
            $count = 10;
            $bad = -5;

            expect(function () use (&$count, &$bad): void {
                /** @var positive-int $count */
                $count = &$bad;
            })->toThrow(TypeError::class, 'positive-int');
            expect($count)->toBe(10);

            $good = 25;
            $count = &$good;
            expect($count)->toBe(25);

            $good = 30;
            expect($count)->toBe(30);
        });

        test('validates assign-by-reference on typed array shape dimension', function () {
            /** @var array{count: positive-int} $stats */
            $stats = ['count' => 1];
            $bad = -5;

            expect(function () use (&$stats, &$bad): void {
                /** @var array{count: positive-int} $stats */
                $stats['count'] = &$bad;
            })->toThrow(TypeError::class, 'positive-int');
            expect($stats['count'])->toBe(1);
        });

        test('validates assign-by-reference on typed object array property dimension', function () {
            $holder = new RefTypedHolder();
            $bad = -99;

            expect(function () use ($holder, &$bad): void {
                $holder->numbers[0] = &$bad;
            })->toThrow(TypeError::class, 'positive-int');
            expect($holder->numbers[0])->toBe(1);
        });
    });

    describe('4. Impure Target Single-Evaluation on AssignRef', function () {
        test('evaluates impure property receiver only once during assign-by-reference', function () {
            $factory = new RefBoxFactory();
            $source = new RefBox(new RefUser('Updated'));

            $factory->next()->item = &$source->item;

            expect($factory->calls)->toBe(1)
                ->and($factory->box->item->name)->toBe('Updated')
            ;

            $source->item = new RefUser('SharedAfter');
            expect($factory->box->item->name)->toBe('SharedAfter');
        });
    });
});
