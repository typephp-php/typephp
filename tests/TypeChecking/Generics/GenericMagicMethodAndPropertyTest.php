<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;

final class MagicGenUser
{
    public function __construct(public string $name)
    {
    }
}

final class MagicGenProduct
{
    public function __construct(public string $sku)
    {
    }
}

/**
 * @template T
 *
 * @property T $item
 *
 * @method T getMagicItem()
 * @method void setMagicItem(T $value)
 * @method void setMagicItems(list<T> $items)
 * @method list<T> getMagicItems()
 */
class GenericMagicBox
{
    private mixed $data = null;

    /**
     * @var list<mixed>
     */
    private array $listData = [];

    public function forceCorruptData(mixed $raw): void
    {
        $this->data = $raw;
    }

    /**
     * @param list<mixed> $raw
     */
    public function forceCorruptListData(array $raw): void
    {
        $this->listData = $raw;
    }

    public function __get(string $name): mixed
    {
        return $this->data;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data = $value;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($name === 'getMagicItem') {
            return $this->data;
        }
        if ($name === 'setMagicItem') {
            $this->data = $args[0];

            return null;
        }
        if ($name === 'setMagicItems') {
            $this->listData = $args[0];

            return null;
        }
        if ($name === 'getMagicItems') {
            return $this->listData;
        }

        return null;
    }
}

/**
 * 1. Concrete @extends specialization
 *
 * @extends GenericMagicBox<MagicGenUser>
 */
final class ConcreteUserMagicBox extends GenericMagicBox
{
}

/**
 * 2. Multi-level generic @extends with renamed template U -> T
 *
 * @template U
 *
 * @extends GenericMagicBox<U>
 */
class RenamedGenericMagicBox extends GenericMagicBox
{
}

/**
 * @extends RenamedGenericMagicBox<MagicGenUser>
 */
final class LeafUserMagicBox extends RenamedGenericMagicBox
{
}

/**
 * 3. Child adding its own @method referencing parent's @template T
 *
 * @extends GenericMagicBox<MagicGenUser>
 *
 * @method T echoChildMagic(T $val)
 */
final class ExtendedMethodMagicBox extends GenericMagicBox
{
    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($name === 'echoChildMagic') {
            return $args[0];
        }

        return parent::__call($name, $args);
    }
}

/**
 * 4. Generic interface with @method contracts
 *
 * @template T
 *
 * @method T fetchMagic()
 * @method void storeMagic(T $val)
 */
interface GenericMagicContractInterface
{
}

/**
 * @implements GenericMagicContractInterface<MagicGenUser>
 */
final class InterfaceMagicHolder implements GenericMagicContractInterface
{
    private mixed $stored = null;

    public function forceCorruptStored(mixed $raw): void
    {
        $this->stored = $raw;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($name === 'storeMagic') {
            $this->stored = $args[0];

            return null;
        }
        if ($name === 'fetchMagic') {
            return $this->stored;
        }

        return null;
    }
}

/**
 * 5. Trait composition (@use) with generic @property and @method
 *
 * @template T
 *
 * @property T $traitItem
 *
 * @method T getTraitMagic()
 * @method void setTraitMagic(T $value)
 */
trait GenericMagicBehaviorTrait
{
    private mixed $traitData = null;

    public function forceCorruptTraitData(mixed $raw): void
    {
        $this->traitData = $raw;
    }

    public function __get(string $name): mixed
    {
        return $this->traitData;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->traitData = $value;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($name === 'getTraitMagic') {
            return $this->traitData;
        }
        if ($name === 'setTraitMagic') {
            $this->traitData = $args[0];

            return null;
        }

        return null;
    }
}

final class ConcreteTraitMagicBox
{
    /** @use GenericMagicBehaviorTrait<MagicGenUser> */
    use GenericMagicBehaviorTrait;
}

/**
 * @template U
 */
final class ForwardedTraitMagicBox
{
    /** @use GenericMagicBehaviorTrait<U> */
    use GenericMagicBehaviorTrait;
}

/**
 * 6. Object composition: generic magic container wrapping GenericMagicBox<T>
 *
 * @template T
 *
 * @property GenericMagicBox<T> $innerBox
 *
 * @method GenericMagicBox<T> getInnerBox()
 * @method void setInnerBox(GenericMagicBox<T> $box)
 */
final class ComposedMagicContainer
{
    private mixed $inner = null;

    public function forceCorruptInner(mixed $raw): void
    {
        $this->inner = $raw;
    }

    public function __get(string $name): mixed
    {
        return $this->inner;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->inner = $value;
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $name, array $args): mixed
    {
        if ($name === 'setInnerBox') {
            $this->inner = $args[0];

            return null;
        }
        if ($name === 'getInnerBox') {
            return $this->inner;
        }

        return null;
    }
}

/**
 * 7. Static generic magic methods (__callStatic) with @extends
 *
 * @template T
 *
 * @method static T echoStaticMagic(T $value)
 * @method static T returnCorruptStaticMagic(mixed $raw)
 */
abstract class StaticGenericMagicBase
{
    /**
     * @param array<int|string, mixed> $args
     */
    public static function __callStatic(string $name, array $args): mixed
    {
        return $args[0] ?? null;
    }
}

/**
 * @extends StaticGenericMagicBase<MagicGenUser>
 */
final class StaticUserMagicBox extends StaticGenericMagicBase
{
}

describe('Generic Magic Methods & Properties with Inheritance and Composition', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    test('enforces inherited generic @property and @method via concrete @extends without @var', function () {
        Config::set(['magic_properties' => ['write' => true, 'read' => true]]);

        $box = new ConcreteUserMagicBox();
        $alice = new MagicGenUser('Alice');

        $box->item = $alice;
        expect($box->item)->toBe($alice);

        expect(function () use ($box): void {
            $box->item = new MagicGenProduct('SKU-EXT-1');
        })->toThrow(TypeError::class, MagicGenUser::class);

        $box->setMagicItem($alice);
        expect($box->getMagicItem())->toBe($alice);

        expect(fn () => $box->setMagicItem(new MagicGenProduct('SKU-EXT-2')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $box->forceCorruptData(new MagicGenProduct('SKU-EXT-3'));
        expect(fn () => $box->getMagicItem())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
        expect(fn () => $box->item)
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces multi-level @extends with template renaming (U -> T) both via @var and concrete leaf class', function () {
        /** @var RenamedGenericMagicBox<MagicGenUser> $mid */
        $mid = new RenamedGenericMagicBox();
        $mid->setMagicItem(new MagicGenUser('MidUser'));

        expect(fn () => $mid->setMagicItem(new MagicGenProduct('SKU-MID')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $leaf = new LeafUserMagicBox();
        $leaf->setMagicItem(new MagicGenUser('LeafUser'));
        expect($leaf->getMagicItem()->name)->toBe('LeafUser');

        expect(fn () => $leaf->setMagicItem(new MagicGenProduct('SKU-LEAF-1')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $leaf->forceCorruptData(new MagicGenProduct('SKU-LEAF-2'));
        expect(fn () => $leaf->getMagicItem())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces child-declared @method referencing inherited @template T', function () {
        $box = new ExtendedMethodMagicBox();
        $alice = new MagicGenUser('Alice');

        expect($box->echoChildMagic($alice))->toBe($alice);

        expect(fn () => $box->echoChildMagic(new MagicGenProduct('SKU-CHILD')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces generic @method inherited from @implements interface', function () {
        $holder = new InterfaceMagicHolder();
        $alice = new MagicGenUser('Alice');

        $holder->storeMagic($alice);
        expect($holder->fetchMagic())->toBe($alice);

        expect(fn () => $holder->storeMagic(new MagicGenProduct('SKU-IFACE-1')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $holder->forceCorruptStored(new MagicGenProduct('SKU-IFACE-2'));
        expect(fn () => $holder->fetchMagic())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces generic @property and @method via trait composition (concrete @use and forwarded @use <U>)', function () {
        Config::set(['magic_properties' => ['write' => true, 'read' => true]]);

        $concrete = new ConcreteTraitMagicBox();
        $alice = new MagicGenUser('Alice');

        $concrete->traitItem = $alice;
        expect($concrete->traitItem)->toBe($alice);
        expect(function () use ($concrete): void {
            $concrete->traitItem = new MagicGenProduct('SKU-TR-1');
        })->toThrow(TypeError::class, MagicGenUser::class);

        $concrete->setTraitMagic($alice);
        expect($concrete->getTraitMagic())->toBe($alice);
        expect(fn () => $concrete->setTraitMagic(new MagicGenProduct('SKU-TR-2')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $concrete->forceCorruptTraitData(new MagicGenProduct('SKU-TR-3'));
        expect(fn () => $concrete->getTraitMagic())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        /** @var ForwardedTraitMagicBox<MagicGenUser> $forwarded */
        $forwarded = new ForwardedTraitMagicBox();
        $forwarded->setTraitMagic($alice);
        expect($forwarded->getTraitMagic())->toBe($alice);

        expect(fn () => $forwarded->setTraitMagic(new MagicGenProduct('SKU-TR-4')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $forwarded->forceCorruptTraitData(new MagicGenProduct('SKU-TR-5'));
        expect(fn () => $forwarded->getTraitMagic())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces object composition with nested generic magic boxes (@property & @method GenericMagicBox<T>)', function () {
        Config::set(['magic_properties' => ['write' => true, 'read' => true]]);

        /** @var ComposedMagicContainer<MagicGenUser> $container */
        $container = new ComposedMagicContainer();

        /** @var GenericMagicBox<MagicGenUser> $userBox */
        $userBox = new GenericMagicBox();
        /** @var GenericMagicBox<MagicGenProduct> $productBox */
        $productBox = new GenericMagicBox();

        $container->innerBox = $userBox;
        expect($container->innerBox)->toBe($userBox);

        expect(function () use ($container, $productBox): void {
            $container->innerBox = $productBox;
        })->toThrow(TypeError::class, MagicGenUser::class);

        $container->setInnerBox($userBox);
        expect($container->getInnerBox())->toBe($userBox);

        expect(fn () => $container->setInnerBox($productBox))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $container->forceCorruptInner($productBox);
        expect(fn () => $container->getInnerBox())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
        expect(fn () => $container->innerBox)
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces static generic @method (__callStatic) specialized via @extends', function () {
        $alice = new MagicGenUser('Alice');

        expect(StaticUserMagicBox::echoStaticMagic($alice))->toBe($alice);

        expect(fn () => StaticUserMagicBox::echoStaticMagic(new MagicGenProduct('SKU-ST-1')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        expect(fn () => StaticUserMagicBox::returnCorruptStaticMagic(new MagicGenProduct('SKU-ST-2')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });
});

describe('Generic Magic Properties & Magic Methods (@template T + @property / @method)', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    test('enforces generic @property T writes and reads on MagicBox<User>', function () {
        Config::set(['magic_properties' => ['write' => true, 'read' => true]]);

        /** @var GenericMagicBox<MagicGenUser> $userBox */
        $userBox = new GenericMagicBox();

        $alice = new MagicGenUser('Alice');
        $userBox->item = $alice;
        expect($userBox->item)->toBe($alice);

        expect(function () use ($userBox): void {
            $userBox->item = new MagicGenProduct('SKU-1');
        })->toThrow(TypeError::class, MagicGenUser::class);

        $userBox->forceCorruptData(new MagicGenProduct('CORRUPT'));
        expect(fn () => $userBox->item)
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces generic @method parameter T on pre-bound MagicBox<User>', function () {
        /** @var GenericMagicBox<MagicGenUser> $userBox */
        $userBox = new GenericMagicBox();

        $bob = new MagicGenUser('Bob');
        $userBox->setMagicItem($bob);
        expect($userBox->getMagicItem())->toBe($bob);

        expect(fn () => $userBox->setMagicItem(new MagicGenProduct('SKU-2')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces generic @method return type T on MagicBox<User>', function () {
        /** @var GenericMagicBox<MagicGenUser> $userBox */
        $userBox = new GenericMagicBox();

        $userBox->forceCorruptData(new MagicGenProduct('SKU-BAD'));

        expect(fn () => $userBox->getMagicItem())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('enforces compound generic @method parameters and returns (list<T>)', function () {
        /** @var GenericMagicBox<MagicGenUser> $userBox */
        $userBox = new GenericMagicBox();

        $alice = new MagicGenUser('Alice');
        $userBox->setMagicItems([$alice]);
        expect($userBox->getMagicItems())->toBe([$alice]);

        expect(fn () => $userBox->setMagicItems([new MagicGenProduct('SKU-3')]))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;

        $userBox->forceCorruptListData([new MagicGenProduct('SKU-4')]);
        expect(fn () => $userBox->getMagicItems())
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });

    test('infers and locks class template T on first magic method call when no @var is used', function () {
        $box = new GenericMagicBox();

        $box->setMagicItem(new MagicGenUser('Charlie'));

        expect(fn () => $box->setMagicItem(new MagicGenProduct('SKU-5')))
            ->toThrow(TypeError::class, MagicGenUser::class)
        ;
    });
});
