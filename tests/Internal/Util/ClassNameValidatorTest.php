<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Util;

use ArrayAccess;
use Countable;
use DateTime;
use stdClass;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Tests\Fixtures\Domain\User;

describe('ClassNameValidator Unit Tests', function () {
    describe('isValid() - Syntax Validator', function () {
        test('accepts valid simple and namespaced PHP class names', function () {
            expect(ClassNameValidator::isValid('Animal'))->toBeTrue()
                ->and(ClassNameValidator::isValid('User_Service'))->toBeTrue()
                ->and(ClassNameValidator::isValid('_PrivateClass'))->toBeTrue()
                ->and(ClassNameValidator::isValid('Class123'))->toBeTrue()
                ->and(ClassNameValidator::isValid('TypePHP\Tests\Fixtures\Domain\Dog'))->toBeTrue()
                ->and(ClassNameValidator::isValid('\App\Services\UserService'))->toBeTrue()
            ;
        });

        test('accepts anonymous class names registered in memory', function () {
            $anon = new class () {};
            expect(ClassNameValidator::isValid($anon::class))->toBeTrue();
        });

        test('rejects generic type annotations and complex PHPDoc types', function () {
            expect(ClassNameValidator::isValid('Producer<Dog>'))->toBeFalse()
                ->and(ClassNameValidator::isValid('Repository<T>'))->toBeFalse()
                ->and(ClassNameValidator::isValid('array{id: int, name: string}'))->toBeFalse()
                ->and(ClassNameValidator::isValid('int<1, 100>'))->toBeFalse()
                ->and(ClassNameValidator::isValid('string[]'))->toBeFalse()
                ->and(ClassNameValidator::isValid('User|Admin'))->toBeFalse()
                ->and(ClassNameValidator::isValid('Countable&ArrayAccess'))->toBeFalse()
            ;
        });

        test('rejects syntactically invalid class identifiers and non-strings', function () {
            expect(ClassNameValidator::isValid('123InvalidClass'))->toBeFalse()
                ->and(ClassNameValidator::isValid('Invalid-Class-Name'))->toBeFalse()
                ->and(ClassNameValidator::isValid(''))->toBeFalse()
                ->and(ClassNameValidator::isValid('\\'))->toBeFalse()
                ->and(ClassNameValidator::isValid(123))->toBeFalse()
                ->and(ClassNameValidator::isValid(null))->toBeFalse()
                ->and(ClassNameValidator::isValid([]))->toBeFalse()
                ->and(ClassNameValidator::isValid(new stdClass()))->toBeFalse()
            ;
        });

        test('retrieves cached results on subsequent calls to isValid', function () {
            expect(ClassNameValidator::isValid('App\Models\Order'))->toBeTrue();
            expect(ClassNameValidator::isValid('App\Models\Order'))->toBeTrue();

            expect(ClassNameValidator::isValid('123Bad'))->toBeFalse();
            expect(ClassNameValidator::isValid('123Bad'))->toBeFalse();
        });
    });

    describe('isValidClassString() - Class-String Validator', function () {
        test('accepts existing unqualified built-in PHP classes and interfaces', function () {
            expect(ClassNameValidator::isValidClassString('stdClass'))->toBeTrue()
                ->and(ClassNameValidator::isValidClassString('DateTime'))->toBeTrue()
                ->and(ClassNameValidator::isValidClassString('Countable'))->toBeTrue()
                ->and(ClassNameValidator::isValidClassString('ArrayAccess'))->toBeTrue()
            ;
        });

        test('rejects non-existent unqualified class names', function () {
            expect(ClassNameValidator::isValidClassString('NonExistentUnqualifiedClass'))->toBeFalse()
                ->and(ClassNameValidator::isValidClassString('RandomUnknownName'))->toBeFalse()
            ;
        });

        test('accepts existing fully qualified class-string names with autoloading', function () {
            expect(ClassNameValidator::isValidClassString(stdClass::class))->toBeTrue()
                ->and(ClassNameValidator::isValidClassString(User::class))->toBeTrue()
                ->and(ClassNameValidator::isValidClassString('\TypePHP\Tests\Fixtures\Domain\Dog'))->toBeTrue()
            ;
        });

        test('accepts syntactically valid qualified synthetic class-strings', function () {
            expect(ClassNameValidator::isValidClassString('App\Models\User'))->toBeTrue()
                ->and(ClassNameValidator::isValidClassString('\Vendor\Package\CustomModel'))->toBeTrue()
            ;
        });

        test('rejects non-strings, empty strings, and invalid syntax in isValidClassString', function () {
            expect(ClassNameValidator::isValidClassString(''))->toBeFalse()
                ->and(ClassNameValidator::isValidClassString(null))->toBeFalse()
                ->and(ClassNameValidator::isValidClassString(12345))->toBeFalse()
                ->and(ClassNameValidator::isValidClassString([]))->toBeFalse()
                ->and(ClassNameValidator::isValidClassString('Invalid-Syntax!'))->toBeFalse()
                ->and(ClassNameValidator::isValidClassString('123InvalidStart'))->toBeFalse()
            ;
        });

        test('retrieves cached results on subsequent calls to isValidClassString', function () {
            expect(ClassNameValidator::isValidClassString(stdClass::class))->toBeTrue();
            expect(ClassNameValidator::isValidClassString(stdClass::class))->toBeTrue();

            expect(ClassNameValidator::isValidClassString('UnknownUnqualified'))->toBeFalse();
            expect(ClassNameValidator::isValidClassString('UnknownUnqualified'))->toBeFalse();
        });
    });
});