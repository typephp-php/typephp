<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

/**
 * Invariant generic interface (@template T)
 *
 * @template T
 */
interface MutualComparable
{
    /**
     * @param T $other
     */
    public function compareTo(mixed $other): int;
}

/**
 * Declaration-site Covariant generic interface (@template-covariant T)
 *
 * @template-covariant T
 */
interface CovariantMutualComparable
{
    /**
     * @return T
     */
    public function getPartner(): mixed;
}

/**
 * Declaration-site Contravariant generic interface (@template-contravariant T)
 *
 * @template-contravariant T
 */
interface ContravariantMutualComparable
{
    /**
     * @param T $other
     */
    public function acceptPartner(mixed $other): void;
}

/**
 * @implements MutualComparable<MutualUser>
 */
final class MutualUser implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * @implements MutualComparable<MutualAdmin>
 */
final class MutualAdmin implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * Implements MutualComparable<MutualUser>, NOT MutualComparable<MutualMalicious>
 *
 * @implements MutualComparable<MutualUser>
 */
final class MutualMalicious implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * Valid asymmetric pair: Lock is comparable to Key, and Key is comparable to Lock
 *
 * @implements MutualComparable<MutualKey>
 */
final class MutualLock implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * @implements MutualComparable<MutualLock>
 */
final class MutualKey implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

class MutualBaseRole
{
}

/**
 * @implements MutualComparable<MutualAdminRole>
 */
class MutualMemberRole extends MutualBaseRole implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * Implements MutualComparable<MutualSuperAdminRole> (where SuperAdminRole is a subtype of MemberRole)
 *
 * @implements MutualComparable<MutualSuperAdminRole>
 */
class MutualAdminRole extends MutualMemberRole implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * @implements MutualComparable<MutualSuperAdminRole>
 */
class MutualSuperAdminRole extends MutualAdminRole implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * Implements MutualComparable<MutualBaseRole> (where BaseRole is a supertype of MemberRole)
 *
 * @implements MutualComparable<MutualBaseRole>
 */
class MutualBadSuperRole extends MutualAdminRole implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

/**
 * Implements invariant MutualComparable<MutualBaseRole> (supertype of MutualBroadRole)
 *
 * @implements MutualComparable<MutualBaseRole>
 */
class MutualBroadRole extends MutualBaseRole implements MutualComparable
{
    public function compareTo(mixed $other): int
    {
        return 0;
    }
}

class CovariantBaseRole
{
}

/**
 * @implements CovariantMutualComparable<CovariantAdminRole>
 */
class CovariantMemberRole extends CovariantBaseRole implements CovariantMutualComparable
{
    public function getPartner(): mixed
    {
        return null;
    }
}

/**
 * @implements CovariantMutualComparable<CovariantSuperAdminRole>
 */
class CovariantAdminRole extends CovariantMemberRole implements CovariantMutualComparable
{
    public function getPartner(): mixed
    {
        return null;
    }
}

class CovariantSuperAdminRole extends CovariantAdminRole
{
}

/**
 * @implements CovariantMutualComparable<CovariantBaseRole>
 */
class CovariantBadSuperRole extends CovariantAdminRole implements CovariantMutualComparable
{
    public function getPartner(): mixed
    {
        return null;
    }
}

class ContraBaseRole
{
}

/**
 * Implements ContravariantMutualComparable<ContraBaseRole> (supertype of ContraSubRole)
 *
 * @implements ContravariantMutualComparable<ContraBaseRole>
 */
class ContraSubRole extends ContraBaseRole implements ContravariantMutualComparable
{
    public function acceptPartner(mixed $other): void
    {
    }
}

class ContraSubSubRole extends ContraSubRole
{
}

/**
 * Implements ContravariantMutualComparable<ContraSubSubRole> (narrower subtype of ContraSubRole)
 *
 * @implements ContravariantMutualComparable<ContraSubSubRole>
 */
class ContraNarrowRole extends ContraBaseRole implements ContravariantMutualComparable
{
    public function acceptPartner(mixed $other): void
    {
    }
}

// =========================================================================
// Functions & Classes Under Test
// =========================================================================

/**
 * Standalone function with invariant mutually recursive bounds:
 * T must be MutualComparable<U>
 * U must be MutualComparable<T>
 *
 * @template T of MutualComparable<U>
 * @template U of MutualComparable<T>
 *
 * @param T $a
 * @param U $b
 */
function mutuallyRecursiveCompare(mixed $a, mixed $b): bool
{
    return true;
}

/**
 * Standalone function with class-string mutually recursive bounds
 *
 * @template T of MutualComparable<U>
 * @template U of MutualComparable<T>
 *
 * @param class-string<T> $classA
 * @param class-string<U> $classB
 */
function mutuallyRecursiveClassStrings(string $classA, string $classB): bool
{
    return true;
}

/**
 * Generic class with invariant mutually recursive bounds
 *
 * @template T of MutualComparable<U>
 * @template U of MutualComparable<T>
 */
final class MutualPairBox
{
    /**
     * @param T $first
     * @param U $second
     */
    public function __construct(
        public mixed $first,
        public mixed $second,
    ) {
    }
}

/**
 * Standalone function with declaration-site covariant mutually recursive bounds
 *
 * @template T of CovariantMutualComparable<U>
 * @template U of CovariantMutualComparable<T>
 *
 * @param T $a
 * @param U $b
 */
function covariantMutuallyRecursiveCompare(mixed $a, mixed $b): bool
{
    return true;
}

/**
 * Generic class with declaration-site covariant mutually recursive bounds
 *
 * @template T of CovariantMutualComparable<U>
 * @template U of CovariantMutualComparable<T>
 */
final class CovariantMutualPairBox
{
    /**
     * @param T $first
     * @param U $second
     */
    public function __construct(
        public mixed $first,
        public mixed $second,
    ) {
    }
}

/**
 * Standalone function with declaration-site contravariant mutually recursive bounds
 *
 * @template T of ContravariantMutualComparable<U>
 * @template U of ContravariantMutualComparable<T>
 *
 * @param T $a
 * @param U $b
 */
function contravariantMutuallyRecursiveCompare(mixed $a, mixed $b): bool
{
    return true;
}

/**
 * Generic class with declaration-site contravariant mutually recursive bounds
 *
 * @template T of ContravariantMutualComparable<U>
 * @template U of ContravariantMutualComparable<T>
 */
final class ContravariantMutualPairBox
{
    /**
     * @param T $first
     * @param U $second
     */
    public function __construct(
        public mixed $first,
        public mixed $second,
    ) {
    }
}

/**
 * Standalone function with use-site covariant mutually recursive bounds on invariant interface
 *
 * @template T of MutualComparable<covariant U>
 * @template U of MutualComparable<covariant T>
 *
 * @param T $a
 * @param U $b
 */
function useSiteCovariantMutualCompare(mixed $a, mixed $b): bool
{
    return true;
}

/**
 * Standalone function with use-site contravariant mutually recursive bounds on invariant interface
 *
 * @template T of MutualComparable<contravariant U>
 * @template U of MutualComparable<contravariant T>
 *
 * @param T $a
 * @param U $b
 */
function useSiteContravariantMutualCompare(mixed $a, mixed $b): bool
{
    return true;
}

describe('Mutually Recursive Generic Bounds (@template T of Comparable<U>, @template U of Comparable<T>)', function () {
    describe('1. Standalone Function Mutual Bounds', function () {
        test('accepts valid identical-type mutual bounds (User, User)', function () {
            expect(mutuallyRecursiveCompare(new MutualUser(), new MutualUser()))->toBeTrue();
        });

        test('accepts valid distinct asymmetric mutual bounds (Lock, Key)', function () {
            expect(mutuallyRecursiveCompare(new MutualLock(), new MutualKey()))->toBeTrue();
            expect(mutuallyRecursiveCompare(new MutualKey(), new MutualLock()))->toBeTrue();
        });

        test('rejects mismatched types (User, Admin) where User is Comparable<User> and Admin is Comparable<Admin>', function () {
            expect(fn () => mutuallyRecursiveCompare(new MutualUser(), new MutualAdmin()))
                ->toThrow(TypeError::class)
            ;
        });

        test('rejects (Malicious, Malicious) where Malicious implements Comparable<User> instead of Comparable<Malicious>', function () {
            expect(fn () => mutuallyRecursiveCompare(new MutualMalicious(), new MutualMalicious()))
                ->toThrow(TypeError::class)
            ;
        });

        test('rejects forward-reference mismatch (User, Malicious) where U satisfies Comparable<T> but T violates Comparable<U>', function () {
            expect(fn () => mutuallyRecursiveCompare(new MutualUser(), new MutualMalicious()))
                ->toThrow(TypeError::class)
            ;
        });

        test('rejects reverse mismatch (Malicious, User) where T satisfies Comparable<U> but U violates Comparable<T>', function () {
            expect(fn () => mutuallyRecursiveCompare(new MutualMalicious(), new MutualUser()))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('2. Class-Level Mutual Bounds (MutualPairBox<T, U>)', function () {
        test('accepts valid MutualPairBox<MutualUser, MutualUser> and MutualPairBox<MutualLock, MutualKey>', function () {
            /** @var MutualPairBox<MutualUser, MutualUser> $userBox */
            $userBox = new MutualPairBox(new MutualUser(), new MutualUser());
            expect($userBox->first)->toBeInstanceOf(MutualUser::class);

            /** @var MutualPairBox<MutualLock, MutualKey> $lockKeyBox */
            $lockKeyBox = new MutualPairBox(new MutualLock(), new MutualKey());
            expect($lockKeyBox->first)->toBeInstanceOf(MutualLock::class);
        });

        test('rejects invalid @var pre-binding MutualPairBox<MutualUser, MutualAdmin>', function () {
            expect(function () {
                /** @var MutualPairBox<MutualUser, MutualAdmin> $box */
                $box = new MutualPairBox(new MutualUser(), new MutualAdmin());
            })->toThrow(TypeError::class);
        });

        test('rejects invalid @var pre-binding MutualPairBox<MutualMalicious, MutualMalicious>', function () {
            expect(function () {
                /** @var MutualPairBox<MutualMalicious, MutualMalicious> $box */
                $box = new MutualPairBox(new MutualMalicious(), new MutualMalicious());
            })->toThrow(TypeError::class);
        });

        test('rejects invalid @var pre-binding MutualPairBox<MutualUser, MutualMalicious>', function () {
            expect(function () {
                /** @var MutualPairBox<MutualUser, MutualMalicious> $box */
                $box = new MutualPairBox(new MutualUser(), new MutualMalicious());
            })->toThrow(TypeError::class);
        });

        test('rejects unannotated constructor call new MutualPairBox(new MutualUser(), new MutualAdmin())', function () {
            expect(fn () => new MutualPairBox(new MutualUser(), new MutualAdmin()))
                ->toThrow(TypeError::class)
            ;
        });

        test('rejects unannotated constructor call new MutualPairBox(new MutualUser(), new MutualMalicious())', function () {
            expect(fn () => new MutualPairBox(new MutualUser(), new MutualMalicious()))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('3. class-string<T> & class-string<U> Mutual Bounds', function () {
        test('accepts valid mutually recursive class-strings', function () {
            expect(mutuallyRecursiveClassStrings(MutualUser::class, MutualUser::class))->toBeTrue();
            expect(mutuallyRecursiveClassStrings(MutualLock::class, MutualKey::class))->toBeTrue();
        });

        test('rejects mismatched class-strings (MutualUser::class, MutualAdmin::class)', function () {
            expect(fn () => mutuallyRecursiveClassStrings(MutualUser::class, MutualAdmin::class))
                ->toThrow(TypeError::class)
            ;
        });

        test('rejects forward-reference mismatched class-strings (MutualUser::class, MutualMalicious::class)', function () {
            expect(fn () => mutuallyRecursiveClassStrings(MutualUser::class, MutualMalicious::class))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('4. Variance in Mutually Recursive Bounds (Invariance, Covariance, Contravariance & Use-Site)', function () {
        test('strictly enforces invariance when MutualComparable is invariant (@template T)', function () {
            expect(fn () => mutuallyRecursiveCompare(new MutualMemberRole(), new MutualAdminRole()))
                ->toThrow(TypeError::class)
            ;

            expect(function () {
                /** @var MutualPairBox<MutualMemberRole, MutualAdminRole> $box */
                $box = new MutualPairBox(new MutualMemberRole(), new MutualAdminRole());
            })->toThrow(TypeError::class);

            expect(fn () => mutuallyRecursiveClassStrings(MutualMemberRole::class, MutualAdminRole::class))
                ->toThrow(TypeError::class)
            ;
        });

        test('allows covariant subtypes when interface declares @template-covariant T and rejects supertypes', function () {
            expect(covariantMutuallyRecursiveCompare(new CovariantMemberRole(), new CovariantAdminRole()))->toBeTrue();

            /** @var CovariantMutualPairBox<CovariantMemberRole, CovariantAdminRole> $box */
            $box = new CovariantMutualPairBox(new CovariantMemberRole(), new CovariantAdminRole());
            expect($box->first)->toBeInstanceOf(CovariantMemberRole::class);

            expect(fn () => covariantMutuallyRecursiveCompare(new CovariantMemberRole(), new CovariantBadSuperRole()))
                ->toThrow(TypeError::class)
            ;

            expect(function () {
                /** @var CovariantMutualPairBox<CovariantMemberRole, CovariantBadSuperRole> $badBox */
                $badBox = new CovariantMutualPairBox(new CovariantMemberRole(), new CovariantBadSuperRole());
            })->toThrow(TypeError::class);
        });

        test('allows contravariant supertypes when interface declares @template-contravariant T and rejects subtypes', function () {
            expect(contravariantMutuallyRecursiveCompare(new ContraSubRole(), new ContraSubRole()))->toBeTrue();

            /** @var ContravariantMutualPairBox<ContraSubRole, ContraSubRole> $box */
            $box = new ContravariantMutualPairBox(new ContraSubRole(), new ContraSubRole());
            expect($box->first)->toBeInstanceOf(ContraSubRole::class);

            expect(fn () => contravariantMutuallyRecursiveCompare(new ContraSubRole(), new ContraNarrowRole()))
                ->toThrow(TypeError::class)
            ;

            expect(function () {
                /** @var ContravariantMutualPairBox<ContraSubRole, ContraNarrowRole> $badBox */
                $badBox = new ContravariantMutualPairBox(new ContraSubRole(), new ContraNarrowRole());
            })->toThrow(TypeError::class);
        });

        test('respects use-site covariance (<covariant U>) and use-site contravariance (<contravariant U>) on invariant interface', function () {
            expect(useSiteCovariantMutualCompare(new MutualMemberRole(), new MutualAdminRole()))->toBeTrue();

            expect(fn () => useSiteCovariantMutualCompare(new MutualMemberRole(), new MutualBadSuperRole()))
                ->toThrow(TypeError::class)
            ;

            expect(useSiteContravariantMutualCompare(new MutualBroadRole(), new MutualBroadRole()))->toBeTrue();

            expect(fn () => mutuallyRecursiveCompare(new MutualBroadRole(), new MutualBroadRole()))
                ->toThrow(TypeError::class)
            ;
        });
    });
});
