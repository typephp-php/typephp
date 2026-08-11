<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Services\ShiftedParamService;

/**
 * Standalone function tested with named arguments
 *
 * @param positive-int $id
 * @param non-empty-string $username
 * @param int<1, 100> $age
 */
function testNamedArgsFunction(int $id, string $username, int $age): bool
{
    return true;
}

describe('PHP 8.0+ Named Arguments and Renamed Parameter Positions', function () {

    describe('Standalone Function with Swapped Named Arguments', function () {
        test('accepts valid named arguments passed in completely reversed/swapped order', function () {
            expect(testNamedArgsFunction(age: 25, username: 'Alice', id: 42))->toBeTrue();
        });

        test('throws TypeError on invalid named argument regardless of passed argument position', function () {
            expect(fn () => testNamedArgsFunction(age: 25, username: 'Alice', id: -5))
                ->toThrow(TypeError::class, 'Argument $id must be of type positive-int')
            ;

            expect(fn () => testNamedArgsFunction(username: '', id: 42, age: 25))
                ->toThrow(TypeError::class, 'Argument $username must be of type non-empty-string')
            ;

            expect(fn () => testNamedArgsFunction(id: 42, age: 150, username: 'Alice'))
                ->toThrow(TypeError::class, 'Argument $age')
            ;
        });

    });

    describe('Class Method with Renamed Parameters in Method Inheritance', function () {

        test('correctly maps inherited contracts when child renames parameters and is called with named arguments in random order', function () {
            $service = new ShiftedParamService();
            expect($service->registerUser(notify: true, userRole: 'admin', userId: 100, userName: 'Bob'))->toBeTrue();
        });

        test('throws TypeError on invalid parameter when child renames parameters in method inheritance', function () {
            $service = new ShiftedParamService();

            expect(fn () => $service->registerUser(userRole: 'admin', userName: 'Bob', userId: -10))
                ->toThrow(TypeError::class, 'Argument $userId must be of type positive-int')
            ;

            expect(fn () => $service->registerUser(userRole: 'superadmin', userName: 'Bob', userId: 100))
                ->toThrow(TypeError::class, 'Argument $userRole')
            ;
        });

    });

});
