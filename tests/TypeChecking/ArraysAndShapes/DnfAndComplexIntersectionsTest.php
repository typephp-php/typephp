<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Dnf\DnfService;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;
use TypePHP\Tests\Fixtures\Types\ArrayAccessOnly;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;

describe('DNF & Complex Intersections ((A&B)|null, Shapes, and Generics)', function () {
    describe('Nullable Intersections ((Countable&ArrayAccess)|null)', function () {
        test('accepts null for nullable intersection parameter', function () {
            $service = new DnfService();

            expect($service->processNullableIntersection(null))->toBeNull();
        });

        test('accepts object implementing both Countable and ArrayAccess', function () {
            $service = new DnfService();
            $obj = new CountableArrayAccess();

            expect($service->processNullableIntersection($obj))->toBe(0);
        });

        test('throws TypeError when object only implements Countable', function () {
            $service = new DnfService();

            expect(fn () => $service->processNullableIntersection(new CountableOnly()))
                ->toThrow(TypeError::class, 'must be of type ((Countable & ArrayAccess) | null)');
        });

        test('throws TypeError when object only implements ArrayAccess', function () {
            $service = new DnfService();

            expect(fn () => $service->processNullableIntersection(new ArrayAccessOnly()))
                ->toThrow(TypeError::class, 'must be of type ((Countable & ArrayAccess) | null)');
        });
    });

    describe('Array Shapes with Embedded Intersection Types', function () {
        test('accepts array shape containing valid intersection object and positive-int', function () {
            $service = new DnfService();
            $data = [
                'collection' => new CountableArrayAccess(),
                'id' => 42,
            ];

            expect($service->processShapeWithIntersection($data))->toBe(0);
        });

        test('throws TypeError when shape intersection property is invalid', function () {
            $service = new DnfService();
            $badData = [
                'collection' => new CountableOnly(),
                'id' => 42,
            ];

            expect(fn () => $service->processShapeWithIntersection($badData))
                ->toThrow(TypeError::class, "['collection'] must be of type ArrayAccess");
        });

        test('throws TypeError when shape scalar property is invalid', function () {
            $service = new DnfService();
            $badData = [
                'collection' => new CountableArrayAccess(),
                'id' => -10, // Violates positive-int
            ];

            expect(fn () => $service->processShapeWithIntersection($badData))
                ->toThrow(TypeError::class, "['id'] must be of type positive-int");
        });
    });

    describe('Type Aliases with DNF ((A&B)|(C&D))', function () {
        test('accepts object matching first DNF branch (Countable & ArrayAccess)', function () {
            $service = new DnfService();

            expect($service->processDnfAlias(new CountableArrayAccess()))->toBe(0);
        });

        test('accepts object matching second DNF branch (Iterator & Countable)', function () {
            $service = new DnfService();

            expect($service->processDnfAlias(new ArrayIterator([1, 2, 3])))->toBe(3);
        });

        test('throws TypeError when object fails both DNF intersection branches', function () {
            $service = new DnfService();

            expect(fn () => $service->processDnfAlias(new CountableOnly()))
                ->toThrow(TypeError::class);
        });
    });

    describe('Generic Collections Holding DNF Intersections', function () {
        test('accepts items satisfying either DNF branch in generic collection', function () {
            /** @var GenericCollection<(Countable&ArrayAccess)|(Iterator&Countable)> $collection */
            $collection = new GenericCollection();

            $collection->add(new CountableArrayAccess());
            $collection->add(new ArrayIterator([10, 20]));

            expect($collection->count())->toBe(2);
        });

        test('throws TypeError when item added to generic collection fails all DNF branches', function () {
            /** @var GenericCollection<(Countable&ArrayAccess)|(Iterator&Countable)> $collection */
            $collection = new GenericCollection();

            expect(fn () => $collection->add(new CountableOnly()))
                ->toThrow(TypeError::class);
        });
    });
});