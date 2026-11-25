<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

class VarianceEntity
{
}

class VarianceUser extends VarianceEntity
{
    public function __construct(public string $name = 'user')
    {
    }
}

class VarianceAdmin extends VarianceUser
{
}

/**
 * @template T of VarianceEntity
 */
interface VarianceRepositoryInterface
{
    /**
     * @param T $item
     */
    public function save(mixed $item): void;
}

/**
 * @template T of VarianceEntity
 *
 * @implements VarianceRepositoryInterface<T>
 */
abstract class VarianceBaseRepository implements VarianceRepositoryInterface
{
    /**
     * @var array<int, T>
     */
    protected array $items = [];

    public function save(mixed $item): void
    {
        $this->items[] = $item;
    }
}

/**
 * @extends VarianceBaseRepository<VarianceUser>
 */
class VarianceUserRepository extends VarianceBaseRepository
{
}

/**
 * @extends VarianceBaseRepository<VarianceAdmin>
 */
class VarianceAdminRepository extends VarianceBaseRepository
{
}

/**
 * @extends VarianceBaseRepository<VarianceEntity>
 */
class VarianceEntityRepository extends VarianceBaseRepository
{
}

/**
 * @template TModel of VarianceEntity
 *
 * @implements VarianceRepositoryInterface<TModel>
 */
abstract class RenamedTemplateBaseRepository implements VarianceRepositoryInterface
{
    public function save(mixed $item): void
    {
    }
}

/**
 * @extends RenamedTemplateBaseRepository<VarianceUser>
 */
class RenamedTemplateUserRepository extends RenamedTemplateBaseRepository
{
}

/**
 * @template T of VarianceEntity
 */
class VarianceUserService
{
    /**
     * @param VarianceRepositoryInterface<T> $repo
     */
    public function __construct(private VarianceRepositoryInterface $repo)
    {
    }

    /**
     * @return VarianceRepositoryInterface<T>
     */
    public function getRepo(): VarianceRepositoryInterface
    {
        return $this->repo;
    }
}

/**
 * @template-covariant T of VarianceEntity
 */
class CovariantBox
{
    /**
     * @param T $value
     */
    public function __construct(private mixed $value)
    {
    }

    /**
     * @return T
     */
    public function get(): mixed
    {
        return $this->value;
    }
}

/**
 * @template-contravariant T of VarianceEntity
 */
class ContravariantSink
{
    /**
     * @param T $value
     */
    public function consume(mixed $value): void
    {
    }
}

/**
 * @template T of VarianceEntity
 */
class InvariantBox
{
    /**
     * @param T $value
     */
    public function __construct(public mixed $value)
    {
    }
}

/**
 * @param VarianceUserService<VarianceAdmin> $service
 */
function acceptAdminUserService(VarianceUserService $service): void
{
}

/**
 * @return VarianceUserService<VarianceAdmin>
 */
function returnAdminUserService(VarianceUserRepository $repo): VarianceUserService
{
    return new VarianceUserService($repo);
}

/**
 * @param CovariantBox<VarianceUser> $box
 */
function acceptCovariantUserBox(CovariantBox $box): void
{
}

/**
 * @param CovariantBox<VarianceAdmin> $box
 */
function acceptCovariantAdminBox(CovariantBox $box): void
{
}

/**
 * @param ContravariantSink<VarianceAdmin> $sink
 */
function acceptContravariantAdminSink(ContravariantSink $sink): void
{
}

/**
 * @param ContravariantSink<VarianceUser> $sink
 */
function acceptContravariantUserSink(ContravariantSink $sink): void
{
}

/**
 * @param InvariantBox<covariant VarianceUser> $box
 */
function acceptUseSiteCovariantUserBox(InvariantBox $box): void
{
}

/**
 * @param InvariantBox<covariant VarianceAdmin> $box
 */
function acceptUseSiteCovariantAdminBox(InvariantBox $box): void
{
}

/**
 * @param InvariantBox<contravariant VarianceAdmin> $box
 */
function acceptUseSiteContravariantAdminBox(InvariantBox $box): void
{
}

/**
 * @param InvariantBox<contravariant VarianceUser> $box
 */
function acceptUseSiteContravariantUserBox(InvariantBox $box): void
{
}

/**
 * @param InvariantBox<*> $box
 */
function acceptUseSiteWildcardBox(InvariantBox $box): void
{
}

describe('Interface Generic Invariance (Test 5 Reproduction)', function () {
    test('allows inherited concrete repository matching service template', function () {
        $userRepo = new VarianceUserRepository();
        $userRepo->save(new VarianceUser('Alice'));
        $userRepo->save(new VarianceAdmin('Bob'));

        /** @var VarianceUserService<VarianceUser> $service */
        $service = new VarianceUserService($userRepo);

        expect($service->getRepo())->toBe($userRepo);
    });

    test('rejects passing RepositoryInterface<User> into UserService<Admin> via unnamed @var', function () {
        $userRepo = new VarianceUserRepository();
        $userRepo->save(new VarianceUser('Alice'));

        expect(function () use ($userRepo): void {
            /** @var VarianceUserService<VarianceAdmin> */
            $badService = new VarianceUserService($userRepo);
        })->toThrow(TypeError::class);
    });

    test('rejects passing RepositoryInterface<User> into UserService<Admin> via named @var', function () {
        $userRepo = new VarianceUserRepository();

        expect(function () use ($userRepo): void {
            /** @var VarianceUserService<VarianceAdmin> $badService */
            $badService = new VarianceUserService($userRepo);
        })->toThrow(TypeError::class);
    });

    test('rejects passing UserService<User> into @param UserService<Admin>', function () {
        $userRepo = new VarianceUserRepository();

        expect(function () use ($userRepo): void {
            acceptAdminUserService(new VarianceUserService($userRepo));
        })->toThrow(TypeError::class);
    });

    test('rejects returning UserService<User> from @return UserService<Admin>', function () {
        $userRepo = new VarianceUserRepository();

        expect(function () use ($userRepo): void {
            returnAdminUserService($userRepo);
        })->toThrow(TypeError::class);
    });

    test('rejects supertype specialization RepositoryInterface<User> into UserService<Entity> under invariance', function () {
        $userRepo = new VarianceUserRepository();

        expect(function () use ($userRepo): void {
            /** @var VarianceUserService<VarianceEntity> $badService */
            $badService = new VarianceUserService($userRepo);
        })->toThrow(TypeError::class);
    });

    test('rejects subtype specialization RepositoryInterface<Admin> into UserService<User> under invariance', function () {
        $adminRepo = new VarianceAdminRepository();

        expect(function () use ($adminRepo): void {
            /** @var VarianceUserService<VarianceUser> $badService */
            $badService = new VarianceUserService($adminRepo);
        })->toThrow(TypeError::class);
    });

    test('rejects specialization equal to upper bound RepositoryInterface<Entity> into UserService<Admin>', function () {
        $entityRepo = new VarianceEntityRepository();

        expect(function () use ($entityRepo): void {
            /** @var VarianceUserService<VarianceAdmin> $badService */
            $badService = new VarianceUserService($entityRepo);
        })->toThrow(TypeError::class);
    });

    test('rejects RepositoryInterface<User> into UserService<Admin> even when intermediate class renames template parameter', function () {
        $userRepo = new RenamedTemplateUserRepository();

        expect(function () use ($userRepo): void {
            /** @var VarianceUserService<VarianceAdmin> $badService */
            $badService = new VarianceUserService($userRepo);
        })->toThrow(TypeError::class);
    });
});

describe('Declaration-Site Covariance (@template-covariant)', function () {
    test('allows subtype CovariantBox<Admin> where CovariantBox<User> is expected', function () {
        /** @var CovariantBox<VarianceAdmin> $adminBox */
        $adminBox = new CovariantBox(new VarianceAdmin('Bob'));

        acceptCovariantUserBox($adminBox);

        expect($adminBox->get())->toBeInstanceOf(VarianceAdmin::class);
    });

    test('rejects supertype CovariantBox<User> where CovariantBox<Admin> is expected', function () {
        /** @var CovariantBox<VarianceUser> $userBox */
        $userBox = new CovariantBox(new VarianceUser('Alice'));

        expect(function () use ($userBox): void {
            acceptCovariantAdminBox($userBox);
        })->toThrow(TypeError::class);
    });

    test('rejects upper-bound supertype CovariantBox<Entity> where CovariantBox<Admin> is expected', function () {
        /** @var CovariantBox<VarianceEntity> $entityBox */
        $entityBox = new CovariantBox(new VarianceEntity());

        expect(function () use ($entityBox): void {
            acceptCovariantAdminBox($entityBox);
        })->toThrow(TypeError::class);
    });
});

describe('Declaration-Site Contravariance (@template-contravariant)', function () {
    test('allows supertype ContravariantSink<User> where ContravariantSink<Admin> is expected', function () {
        /** @var ContravariantSink<VarianceUser> $userSink */
        $userSink = new ContravariantSink();

        acceptContravariantAdminSink($userSink);

        expect(true)->toBeTrue();
    });

    test('rejects subtype ContravariantSink<Admin> where ContravariantSink<User> is expected', function () {
        /** @var ContravariantSink<VarianceAdmin> $adminSink */
        $adminSink = new ContravariantSink();

        expect(function () use ($adminSink): void {
            acceptContravariantUserSink($adminSink);
        })->toThrow(TypeError::class);
    });
});

describe('Use-Site Variance (covariant, contravariant, wildcard)', function () {
    test('allows subtype InvariantBox<Admin> into InvariantBox<covariant User>', function () {
        /** @var InvariantBox<VarianceAdmin> $adminBox */
        $adminBox = new InvariantBox(new VarianceAdmin('Bob'));

        acceptUseSiteCovariantUserBox($adminBox);

        expect($adminBox->value)->toBeInstanceOf(VarianceAdmin::class);
    });

    test('rejects supertype InvariantBox<User> into InvariantBox<covariant Admin>', function () {
        /** @var InvariantBox<VarianceUser> $userBox */
        $userBox = new InvariantBox(new VarianceUser('Alice'));

        expect(function () use ($userBox): void {
            acceptUseSiteCovariantAdminBox($userBox);
        })->toThrow(TypeError::class);
    });

    test('allows supertype InvariantBox<User> into InvariantBox<contravariant Admin>', function () {
        /** @var InvariantBox<VarianceUser> $userBox */
        $userBox = new InvariantBox(new VarianceUser('Alice'));

        acceptUseSiteContravariantAdminBox($userBox);

        expect($userBox->value)->toBeInstanceOf(VarianceUser::class);
    });

    test('rejects subtype InvariantBox<Admin> into InvariantBox<contravariant User>', function () {
        /** @var InvariantBox<VarianceAdmin> $adminBox */
        $adminBox = new InvariantBox(new VarianceAdmin('Bob'));

        expect(function () use ($adminBox): void {
            acceptUseSiteContravariantUserBox($adminBox);
        })->toThrow(TypeError::class);
    });

    test('allows any specialization into InvariantBox<*>', function () {
        /** @var InvariantBox<VarianceUser> $userBox */
        $userBox = new InvariantBox(new VarianceUser('Alice'));
        /** @var InvariantBox<VarianceAdmin> $adminBox */
        $adminBox = new InvariantBox(new VarianceAdmin('Bob'));

        acceptUseSiteWildcardBox($userBox);
        acceptUseSiteWildcardBox($adminBox);

        expect(true)->toBeTrue();
    });
});
