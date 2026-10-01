<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

class StaticPropModel
{
}

class StaticPropUser extends StaticPropModel
{
    public function __construct(public string $name = 'Alice')
    {
    }
}

class StaticPropAdmin extends StaticPropUser
{
}

class StaticPropProduct
{
    public function __construct(public string $sku = 'SKU-001')
    {
    }
}

/**
 * Base generic class with static property using template T
 *
 * @template T
 */
abstract class BaseGenericStaticRegistry
{
    /**
     * @var T|null
     */
    public static mixed $current = null;
}

/**
 * Closed generic subclass binding T to StaticPropUser
 *
 * @extends BaseGenericStaticRegistry<StaticPropUser>
 */
class UserStaticRegistry extends BaseGenericStaticRegistry
{
}

/**
 * Closed generic subclass binding T to StaticPropAdmin
 *
 * @extends BaseGenericStaticRegistry<StaticPropAdmin>
 */
class AdminStaticRegistry extends BaseGenericStaticRegistry
{
}

/**
 * @template T of StaticPropModel
 */
class BoundedStaticStorage
{
    /**
     * @var T|null
     */
    public static mixed $item = null;
}

/**
 * @template T
 */
class UnboundStaticBucket
{
    /**
     * @var T|null
     */
    public static mixed $element = null;
}

/**
 * @template T
 */
trait HasGenericStaticCache
{
    /**
     * @var T|null
     */
    public static mixed $cached = null;
}

class UserCachedService
{
    /**
     * @use HasGenericStaticCache<StaticPropUser>
     */
    use HasGenericStaticCache;
}

describe('Static Generic Property Contract Enforcement', function () {
    beforeEach(function () {
        UserStaticRegistry::$current = null;
        AdminStaticRegistry::$current = null;
        BoundedStaticStorage::$item = null;
        UnboundStaticBucket::$element = null;
        UserCachedService::$cached = null;
    });

    afterEach(function () {
        UserStaticRegistry::$current = null;
        AdminStaticRegistry::$current = null;
        BoundedStaticStorage::$item = null;
        UnboundStaticBucket::$element = null;
        UserCachedService::$cached = null;
    });

    describe('1. Parameterized Subclasses (@extends Base<ConcreteType>)', function () {
        test('accepts valid instance matching @extends generic parameter on static property', function () {
            $user = new StaticPropUser('Alice');
            UserStaticRegistry::$current = $user;

            expect(UserStaticRegistry::$current)->toBe($user);
        });

        test('accepts subtype of @extends generic parameter (Admin is a User)', function () {
            $admin = new StaticPropAdmin('SuperAdmin');
            UserStaticRegistry::$current = $admin;

            expect(UserStaticRegistry::$current)->toBe($admin);
        });

        test('rejects unrelated class assigned to statically parameterized property', function () {
            expect(function () {
                UserStaticRegistry::$current = new StaticPropProduct('SKU-100');
            })->toThrow(TypeError::class, 'StaticPropUser');
        });

        test('strictly enforces narrower binding on specialized subclass (Admin vs User)', function () {
            $admin = new StaticPropAdmin('AdminOnly');
            AdminStaticRegistry::$current = $admin;
            expect(AdminStaticRegistry::$current)->toBe($admin);

            expect(function () {
                AdminStaticRegistry::$current = new StaticPropUser('RegularUser');
            })->toThrow(TypeError::class, 'StaticPropAdmin');
        });
    });

    describe('2. Unparameterized Class with Upper Bound (@template T of Model)', function () {
        test('accepts any subtype satisfying declared upper bound on static property', function () {
            $user = new StaticPropUser('Bob');
            BoundedStaticStorage::$item = $user;
            expect(BoundedStaticStorage::$item)->toBe($user);

            $admin = new StaticPropAdmin('AdminBob');
            BoundedStaticStorage::$item = $admin;
            expect(BoundedStaticStorage::$item)->toBe($admin);
        });

        test('rejects class violating declared upper bound on unparameterized static property', function () {
            expect(function () {
                BoundedStaticStorage::$item = new StaticPropProduct('SKU-FAIL');
            })->toThrow(TypeError::class, 'StaticPropModel');
        });

        test('rejects primitive violating object upper bound on unparameterized static property', function () {
            expect(function () {
                BoundedStaticStorage::$item = 'scalar_string';
            })->toThrow(TypeError::class, 'StaticPropModel');
        });
    });

    describe('3. Unparameterized Class with Unbound Template (@template T falls back to mixed)', function () {
        test('allows any value without throwing false "must be of type T" error', function () {
            UnboundStaticBucket::$element = 42;
            expect(UnboundStaticBucket::$element)->toBe(42);

            UnboundStaticBucket::$element = 'hello';
            expect(UnboundStaticBucket::$element)->toBe('hello');

            $user = new StaticPropUser('Charlie');
            UnboundStaticBucket::$element = $user;
            expect(UnboundStaticBucket::$element)->toBe($user);
        });
    });

    describe('4. Traits with Generic Static Properties (@use Trait<Type>)', function () {
        test('resolves trait template binding on static property assigned through class', function () {
            $user = new StaticPropUser('Dave');
            UserCachedService::$cached = $user;

            expect(UserCachedService::$cached)->toBe($user);
        });

        test('rejects invalid type assigned to trait static property', function () {
            expect(function () {
                UserCachedService::$cached = new StaticPropProduct('SKU-TRAIT');
            })->toThrow(TypeError::class, 'StaticPropUser');
        });
    });
});
