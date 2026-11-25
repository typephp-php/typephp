<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\TypePHP;

class PrebindNullableAnimal
{
}

class PrebindNullableDog extends PrebindNullableAnimal
{
}

class PrebindNullableCat extends PrebindNullableAnimal
{
}

class PrebindNullableCar
{
}

/**
 * @template T
 */
class PrebindNullableBox
{
    public ?string $typeDuringConstruct = null;

    public bool $bodyExecuted = false;

    /**
     * @param T[] $content
     */
    public function __construct(public array $content = [])
    {
        $this->typeDuringConstruct = TypePHP::getGenericType($this);
        $this->bodyExecuted = true;
    }

    /**
     * @param T $item
     */
    public function add(mixed $item): void
    {
        $this->content[] = $item;
    }

    /**
     * @return T[]
     */
    public function getContent(): array
    {
        return $this->content;
    }
}

describe('Nullable & Union Generic Constructor Pre-binding', function () {
    describe('0. Control: Direct GenericTypeNode Pre-binding', function () {
        test('prebinds T inside constructor and validates elements on entry', function () {
            /** @var PrebindNullableBox<PrebindNullableDog> $box */
            $box = new PrebindNullableBox([new PrebindNullableDog()]);

            expect($box->typeDuringConstruct)->toBe(PrebindNullableDog::class)
                ->and($box->bodyExecuted)->toBeTrue()
            ;

            expect(function () {
                /** @var PrebindNullableBox<PrebindNullableDog> $bad */
                $bad = new PrebindNullableBox([new PrebindNullableCat()]);
            })->toThrow(
                TypeError::class,
                'Argument $content[0] (template T = ' . PrebindNullableDog::class . ') must be of type ' . PrebindNullableDog::class
            );
        });
    });

    describe('1. Nullable Generic (?Box<T>)', function () {
        test('prebinds template T during __construct() for ?Box<Dog>', function () {
            /** @var ?PrebindNullableBox<PrebindNullableDog> $box */
            $box = new PrebindNullableBox([new PrebindNullableDog()]);

            expect($box->typeDuringConstruct)->toBe(PrebindNullableDog::class)
                ->and(TypePHP::getGenericType($box))->toBe(PrebindNullableDog::class)
            ;
        });

        test('prebinds ?Box<Animal> before constructor so [new Dog()] does not lock T=Dog and cause invariance mismatch', function () {
            /** @var ?PrebindNullableBox<PrebindNullableAnimal> $box */
            $box = new PrebindNullableBox([new PrebindNullableDog()]);

            expect($box->typeDuringConstruct)->toBe(PrebindNullableAnimal::class)
                ->and(TypePHP::getGenericType($box))->toBe(PrebindNullableAnimal::class)
            ;

            $box->add(new PrebindNullableCat());
            expect($box->getContent())->toHaveCount(2);
        });

        test('rejects invalid constructor items at parameter boundary before constructor body runs on ?Box<Dog>', function () {
            $bodyRan = false;

            $probe = new class () extends PrebindNullableBox {
                public static bool $ran = false;

                /**
                 * @param T[] $content
                 */
                public function __construct(array $content = [])
                {
                    self::$ran = true;
                    parent::__construct($content);
                }
            };
            $probeClass = $probe::class;
            $probeClass::$ran = false;

            expect(function () use ($probeClass) {
                /** @var ?PrebindNullableBox<PrebindNullableDog> $box */
                $box = new $probeClass([new PrebindNullableCat()]);
            })->toThrow(
                TypeError::class,
                'Argument $content[0] (template T = ' . PrebindNullableDog::class . ')'
            );

            expect($probeClass::$ran)->toBeFalse();
        });

        test('allows null assignment to ?Box<Dog>', function () {
            /** @var ?PrebindNullableBox<PrebindNullableDog> $box */
            $box = null;

            expect($box)->toBeNull();
        });
    });

    describe('2. Union Generics (Box<T>|null, null|Box<T>, Box<T>|Other)', function () {
        test('prebinds template T during __construct() for Box<Dog>|null', function () {
            /** @var PrebindNullableBox<PrebindNullableDog>|null $box */
            $box = new PrebindNullableBox([new PrebindNullableDog()]);

            expect($box->typeDuringConstruct)->toBe(PrebindNullableDog::class);
        });

        test('prebinds template T during __construct() for null|Box<Dog>', function () {
            /** @var null|PrebindNullableBox<PrebindNullableDog> $box */
            $box = new PrebindNullableBox([new PrebindNullableDog()]);

            expect($box->typeDuringConstruct)->toBe(PrebindNullableDog::class);
        });

        test('prebinds Box<Animal>|null and null|Box<Animal> without false-positive invariance mismatch on [new Dog()]', function () {
            /** @var PrebindNullableBox<PrebindNullableAnimal>|null $boxA */
            $boxA = new PrebindNullableBox([new PrebindNullableDog()]);
            expect($boxA->typeDuringConstruct)->toBe(PrebindNullableAnimal::class);

            /** @var null|PrebindNullableBox<PrebindNullableAnimal> $boxB */
            $boxB = new PrebindNullableBox([new PrebindNullableDog()]);
            expect($boxB->typeDuringConstruct)->toBe(PrebindNullableAnimal::class);
        });

        test('rejects invalid constructor item at constructor parameter boundary for Box<Dog>|null', function () {
            expect(function () {
                /** @var PrebindNullableBox<PrebindNullableDog>|null $box */
                $box = new PrebindNullableBox([new PrebindNullableCat()]);
            })->toThrow(
                TypeError::class,
                'Argument $content[0] (template T = ' . PrebindNullableDog::class . ')'
            );
        });

        test('prebinds Box<Dog> when unioned with unrelated non-generic class (Box<Dog>|Car)', function () {
            /** @var PrebindNullableBox<PrebindNullableDog>|PrebindNullableCar $box */
            $box = new PrebindNullableBox([new PrebindNullableDog()]);

            expect($box->typeDuringConstruct)->toBe(PrebindNullableDog::class);
        });

        test('prebinds template T during 0-arg __construct() for ?Box<Dog> and Box<Dog>|null', function () {
            /** @var ?PrebindNullableBox<PrebindNullableDog> $nullable */
            $nullable = new PrebindNullableBox();

            /** @var PrebindNullableBox<PrebindNullableDog>|null $unionRight */
            $unionRight = new PrebindNullableBox();

            /** @var null|PrebindNullableBox<PrebindNullableDog> $unionLeft */
            $unionLeft = new PrebindNullableBox();

            expect($nullable->typeDuringConstruct)->toBe(PrebindNullableDog::class)
                ->and($unionRight->typeDuringConstruct)->toBe(PrebindNullableDog::class)
                ->and($unionLeft->typeDuringConstruct)->toBe(PrebindNullableDog::class)
            ;
        });
    });

    describe('3. Ambiguous Multi-Generic Unions (Box<Dog>|Box<Cat>)', function () {
        test('does not wrongly prebind first branch Box<Dog> when Box<Cat> is instantiated', function () {
            /** @var PrebindNullableBox<PrebindNullableDog>|PrebindNullableBox<PrebindNullableCat> $box */
            $box = new PrebindNullableBox([new PrebindNullableCat()]);

            expect($box)->toBeInstanceOf(PrebindNullableBox::class)
                ->and(TypePHP::getGenericType($box))->toBe(PrebindNullableCat::class)
            ;
        });
    });

    describe('4. Non-Class Scalar Generics AST Transformation (int<1, 10>)', function () {
        test('does not wrap new expression in withPendingGeneric() when @var is a scalar range int<1, 10>', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

/** @var int<1, 10> $count */
$count = new \stdClass();
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_scalar_range_new.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::withPendingGeneric')
                ->and($transformed)->toContain('RuntimeTypeChecker::checkVariable')
            ;
        });
    });
});
