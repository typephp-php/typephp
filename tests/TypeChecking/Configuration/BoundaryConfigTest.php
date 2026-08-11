<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;

/**
 * Function with ONLY a parameter contract
 *
 * @param positive-int $id
 */
function testParamOnlyFunction(int $id): int
{
    return $id;
}

/**
 * Function with ONLY a return contract
 *
 * @return positive-int
 */
function testReturnOnlyFunction(int $id): int
{
    return $id;
}

describe('Function Boundary Config Toggles (params & returns)', function () {
    afterEach(function () {
        Config::reset();
    });

    test('ignores parameter contract checks when params config is set to false', function () {
        Config::set(['params' => false]);

        $result = testParamOnlyFunction(-50);
        expect($result)->toBe(-50);
    });

    test('ignores return contract checks when returns config is set to false', function () {
        Config::set(['returns' => false]);

        $result = testReturnOnlyFunction(-100);
        expect($result)->toBe(-100);
    });

    test('skips template parameter validation when params config is set to false even if pre-bound', function () {
        Config::set([
            'params' => false,
            'inline_vars' => ['generics' => true],
        ]);

        /** @var GenericCollection<Dog> $dogs */
        $dogs = new GenericCollection();

        $dogs->add(new Car());
        expect($dogs->count())->toBe(1);
    });
});
