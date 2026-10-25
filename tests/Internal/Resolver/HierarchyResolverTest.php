<?php

declare(strict_types=1);

use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Tests\Fixtures\Services\BaseService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;

describe('HierarchyResolver Unit Tests', function () {
    afterEach(function () {
        HierarchyResolver::reset();
    });

    test('resolves class inheritance chain from child to parent root and caches result', function () {
        $ref = new ReflectionClass(UserService::class);

        $hierarchy1 = HierarchyResolver::getClassHierarchy($ref);
        $hierarchy2 = HierarchyResolver::getClassHierarchy($ref);

        $classNames = array_map(fn ($r) => $r->getName(), $hierarchy1);

        expect($classNames)->toContain(UserService::class)
            ->and($classNames)->toContain(BaseService::class)
            ->and($hierarchy1)->toBe($hierarchy2)
        ;
    });

    test('resolves interface inheritance chain for implementing classes', function () {
        $ref = new ReflectionClass(CountableArrayAccess::class);
        $hierarchy = HierarchyResolver::getClassHierarchy($ref);

        $classNames = array_map(fn ($r) => $r->getName(), $hierarchy);

        expect($classNames)->toContain(CountableArrayAccess::class)
            ->and($classNames)->toContain(Countable::class)
            ->and($classNames)->toContain(ArrayAccess::class)
        ;
    });

    test('resolves method hierarchy across parent classes and caches result', function () {
        $ref = new ReflectionMethod(UserService::class, 'find');

        $hierarchy1 = HierarchyResolver::getMethodHierarchy($ref);
        $hierarchy2 = HierarchyResolver::getMethodHierarchy($ref);

        expect($hierarchy1)->toHaveCount(2)
            ->and($hierarchy1[0]->getDeclaringClass()->getName())->toBe(UserService::class)
            ->and($hierarchy1[1]->getDeclaringClass()->getName())->toBe(BaseService::class)
            ->and($hierarchy1)->toBe($hierarchy2) // Exact cached reference!
        ;
    });
});
