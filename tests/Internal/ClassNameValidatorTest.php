<?php

declare(strict_types=1);

use TypePHP\Internal\ClassNameValidator;

describe('ClassNameValidator', function () {
    test('accepts valid simple and namespaced PHP class names', function () {
        expect(ClassNameValidator::isValid('Animal'))->toBeTrue();
        expect(ClassNameValidator::isValid('User_Service'))->toBeTrue();
        expect(ClassNameValidator::isValid('_PrivateClass'))->toBeTrue();
        expect(ClassNameValidator::isValid('Class123'))->toBeTrue();
        expect(ClassNameValidator::isValid('TypePHP\Tests\Fixtures\Domain\Dog'))->toBeTrue();
        expect(ClassNameValidator::isValid('\App\Services\UserService'))->toBeTrue();
    });

    test('rejects generic type annotations and complex PHPDoc types', function () {
        expect(ClassNameValidator::isValid('Producer<Dog>'))->toBeFalse();
        expect(ClassNameValidator::isValid('Repository<T>'))->toBeFalse();
        expect(ClassNameValidator::isValid('array{id: int, name: string}'))->toBeFalse();
        expect(ClassNameValidator::isValid('int<1, 100>'))->toBeFalse();
        expect(ClassNameValidator::isValid('string[]'))->toBeFalse();
        expect(ClassNameValidator::isValid('User|Admin'))->toBeFalse();
        expect(ClassNameValidator::isValid('Countable&ArrayAccess'))->toBeFalse();
    });

    test('rejects syntactically invalid class identifiers and non-strings', function () {
        expect(ClassNameValidator::isValid('123InvalidClass'))->toBeFalse();
        expect(ClassNameValidator::isValid('Invalid-Class-Name'))->toBeFalse();
        expect(ClassNameValidator::isValid(''))->toBeFalse();
        expect(ClassNameValidator::isValid('\\'))->toBeFalse();
        expect(ClassNameValidator::isValid(123))->toBeFalse();
        expect(ClassNameValidator::isValid(null))->toBeFalse();
        expect(ClassNameValidator::isValid([]))->toBeFalse();
        expect(ClassNameValidator::isValid(new stdClass()))->toBeFalse();
    });

    test('accepts anonymous class names registered in memory', function () {
        $anon = new class() {};
        expect(ClassNameValidator::isValid($anon::class))->toBeTrue();
    });
});
