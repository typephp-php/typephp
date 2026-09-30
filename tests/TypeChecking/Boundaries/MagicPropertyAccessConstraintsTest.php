<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;

/**
 * @property-read string $readOnlyName
 * @property-write string $writeOnlyName
 * @property string $readWriteBio
 * @property-read positive-int $asymmetricCounter
 * @property-write int $asymmetricCounter
 */
class AccessConstrainedModel
{
    /**
     * @var array<string, mixed>
     */
    public array $data = [
        'readOnlyName' => 'initial_name',
        'writeOnlyName' => 'secret_val',
        'readWriteBio' => 'initial_bio',
        'asymmetricCounter' => 10,
    ];

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }
}

/**
 * Parent class with magic property annotations
 *
 * @property-read string $parentReadOnly
 * @property-write string $parentWriteOnly
 */
abstract class BaseMagicParent
{
    /**
     * @var array<string, mixed>
     */
    public array $storage = [
        'parentReadOnly' => 'parent_val',
        'parentWriteOnly' => 'parent_secret',
    ];

    public function __get(string $name): mixed
    {
        return $this->storage[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->storage[$name] = $value;
    }
}

class ChildMagicDescendant extends BaseMagicParent
{
}

describe('Magic Property Access Constraints (@property-read and @property-write)', function () {
    describe('Write Permission Enforcement', function () {
        test('throws TypeError when attempting to write to a @property-read property', function () {
            $user = new AccessConstrainedModel();

            expect(fn () => $user->readOnlyName = 'overwritten')
                ->toThrow(TypeError::class, 'Cannot write to read-only property')
            ;
        });

        test('allows reading from a @property-read property', function () {
            $user = new AccessConstrainedModel();

            expect($user->readOnlyName)->toBe('initial_name');
        });

        test('allows writing valid data to a @property-write property', function () {
            $user = new AccessConstrainedModel();

            $user->writeOnlyName = 'new_secret';
            expect($user->data['writeOnlyName'])->toBe('new_secret');
        });

        test('throws TypeError when writing an invalid data type to a @property-write property', function () {
            $user = new AccessConstrainedModel();

            expect(fn () => $user->writeOnlyName = 12345)
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });
    });

    describe('Read Permission Enforcement', function () {
        test('throws TypeError when attempting to read from a @property-write property', function () {
            $user = new AccessConstrainedModel();

            expect(fn () => $user->writeOnlyName)
                ->toThrow(TypeError::class, 'Cannot read from write-only property')
            ;
        });

        test('allows both reading and writing on standard @property annotation', function () {
            $user = new AccessConstrainedModel();

            expect($user->readWriteBio)->toBe('initial_bio');

            $user->readWriteBio = 'updated_bio';
            expect($user->readWriteBio)->toBe('updated_bio');
        });
    });

    describe('Asymmetric Read / Write Types (@property-read TypeA and @property-write TypeB)', function () {
        test('allows writing value matching write type even if it violates read type', function () {
            $user = new AccessConstrainedModel();

            $user->asymmetricCounter = -50;
            expect($user->data['asymmetricCounter'])->toBe(-50);
        });

        test('throws TypeError when writing value that violates write type in asymmetric property', function () {
            $user = new AccessConstrainedModel();

            expect(fn () => $user->asymmetricCounter = 'not_an_int')
                ->toThrow(TypeError::class, 'must be of type int')
            ;
        });
    });

    describe('Inheritance Across Class Hierarchy', function () {
        test('enforces access constraints inherited from parent class docblocks', function () {
            $child = new ChildMagicDescendant();

            expect(fn () => $child->parentReadOnly = 'forbidden_write')
                ->toThrow(TypeError::class, 'Cannot write to read-only property')
            ;

            expect(fn () => $child->parentWriteOnly)
                ->toThrow(TypeError::class, 'Cannot read from write-only property')
            ;
        });
    });

    describe('Configuration Toggles', function () {
        test('bypasses write restriction when magic_properties.write is set to false', function () {
            Config::set(['magic_properties' => ['write' => false]]);

            $user = new AccessConstrainedModel();
            $user->readOnlyName = 'overwritten';

            expect($user->data['readOnlyName'])->toBe('overwritten');
        });

        test('bypasses all checks when magic_properties is globally false', function () {
            Config::set(['magic_properties' => false]);

            $user = new AccessConstrainedModel();
            $user->readOnlyName = 'overwritten';

            expect($user->data['readOnlyName'])->toBe('overwritten');
        });
    });
});
