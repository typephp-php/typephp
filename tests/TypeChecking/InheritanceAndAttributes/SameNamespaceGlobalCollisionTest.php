<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\InheritanceAndAttributes;

use ReflectionMethod;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Tests\Fixtures\Shopware\Error\Error;
use TypePHP\Tests\Fixtures\Shopware\Error\ErrorCollection;
use TypePHP\Tests\Fixtures\Shopware\Error\TestError;

describe('Same-Namespace Resolution Priority over Global Class Names (Shopware ErrorCollection)', function () {
    test('resolves Error in same namespace instead of global \Error class in resolveFqcn', function () {
        $ref = new ReflectionMethod(ErrorCollection::class, '__construct');

        expect(SpecialTypeResolver::resolveFqcn('Error', $ref))
            ->toBe(Error::class)
        ;
    });

    test('accepts TestError in ErrorCollection without global \Error TypeError', function () {
        $testError = new TestError('item out of stock');

        $collection = new ErrorCollection([$testError]);

        expect($collection->elements)->toHaveCount(1)
            ->and($collection->elements[0])->toBe($testError)
        ;
    });
});
