<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\InheritanceAndAttributes;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\Checker\ParamOutChecker;
use TypePHP\Internal\Checker\ReturnChecker;
use TypePHP\Internal\Checker\SelfOutChecker;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\TypePHP;

/**
 * Marker interface so base classes have $hasInheritance = true
 * and receive FunctionContractInjector instrumentation without
 * declaring any type contracts of their own.
 */
interface SiblingCachePoisoningMarkerInterface
{
}

class SiblingCachePoisoningParamBase implements SiblingCachePoisoningMarkerInterface
{
    public function process(mixed $id): mixed
    {
        return $id;
    }
}

class SiblingCachePoisoningParamUnannotatedChild extends SiblingCachePoisoningParamBase
{
}

interface SiblingCachePoisoningStrictParamInterface
{
    /**
     * @param positive-int $id
     */
    public function process(mixed $id): mixed;
}

class SiblingCachePoisoningParamStrictChild extends SiblingCachePoisoningParamBase implements SiblingCachePoisoningStrictParamInterface
{
}

class SiblingCachePoisoningReturnBase implements SiblingCachePoisoningMarkerInterface
{
    public function fetch(): mixed
    {
        return -99;
    }
}

class SiblingCachePoisoningReturnUnannotatedChild extends SiblingCachePoisoningReturnBase
{
}

interface SiblingCachePoisoningStrictReturnInterface
{
    /**
     * @return positive-int
     */
    public function fetch(): mixed;
}

class SiblingCachePoisoningReturnStrictChild extends SiblingCachePoisoningReturnBase implements SiblingCachePoisoningStrictReturnInterface
{
}

class SiblingCachePoisoningParamOutBase implements SiblingCachePoisoningMarkerInterface
{
    public function mutate(mixed &$value): void
    {
    }
}

class SiblingCachePoisoningParamOutUnannotatedChild extends SiblingCachePoisoningParamOutBase
{
}

interface SiblingCachePoisoningStrictParamOutInterface
{
    /**
     * @param-out positive-int $value
     */
    public function mutate(mixed &$value): void;
}

class SiblingCachePoisoningParamOutStrictChild extends SiblingCachePoisoningParamOutBase implements SiblingCachePoisoningStrictParamOutInterface
{
}

/**
 * @template TState of 'idle'|'ready'
 */
abstract class SiblingCachePoisoningSelfOutBase implements SiblingCachePoisoningMarkerInterface
{
    public function transition(): static
    {
        return $this;
    }
}

class SiblingCachePoisoningSelfOutUnannotatedChild extends SiblingCachePoisoningSelfOutBase
{
}

interface SiblingCachePoisoningStrictSelfOutInterface
{
    /**
     * @self-out static<'ready'>
     */
    public function transition(): static;
}

/**
 * @extends SiblingCachePoisoningSelfOutBase<'idle'|'ready'>
 */
class SiblingCachePoisoningSelfOutStrictChild extends SiblingCachePoisoningSelfOutBase implements SiblingCachePoisoningStrictSelfOutInterface
{
}

class SiblingCachePoisoningStaticBase implements SiblingCachePoisoningMarkerInterface
{
    public static function make(mixed $val): mixed
    {
        return $val;
    }
}

class SiblingCachePoisoningStaticUnannotatedChild extends SiblingCachePoisoningStaticBase
{
}

interface SiblingCachePoisoningStrictStaticInterface
{
    /**
     * @param positive-int $val
     *
     * @return positive-int
     */
    public static function make(mixed $val): mixed;
}

class SiblingCachePoisoningStaticStrictChild extends SiblingCachePoisoningStaticBase implements SiblingCachePoisoningStrictStaticInterface
{
}

class SiblingCachePoisoningTemplateBase implements SiblingCachePoisoningMarkerInterface
{
    /**
     * @param int|string $val
     */
    public static function echoValue(int|string $val): int|string
    {
        return $val;
    }
}

/**
 * @template T
 */
class SiblingCachePoisoningTemplatedChild extends SiblingCachePoisoningTemplateBase
{
}

class SiblingCachePoisoningNonTemplatedChild extends SiblingCachePoisoningTemplateBase
{
}

describe('Sibling Inheritance Cache Poisoning Prevention', function () {
    test('does not poison ParamChecker::$noParamContractCache on base method when unannotated sibling runs first', function () {
        $unannotated = new SiblingCachePoisoningParamUnannotatedChild();
        expect($unannotated->process(-5))->toBe(-5);

        expect(ParamChecker::$noParamContractCache)->not()->toHaveKey(SiblingCachePoisoningParamBase::class . '::process');

        $strict = new SiblingCachePoisoningParamStrictChild();

        expect(fn () => $strict->process(-5))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('does not poison ReturnChecker::$noReturnContractCache on base method when unannotated sibling runs first', function () {
        $unannotated = new SiblingCachePoisoningReturnUnannotatedChild();
        expect($unannotated->fetch())->toBe(-99);

        expect(ReturnChecker::$noReturnContractCache)->not()->toHaveKey(SiblingCachePoisoningReturnBase::class . '::fetch');

        $strict = new SiblingCachePoisoningReturnStrictChild();

        expect(fn () => $strict->fetch())
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('does not poison ParamOutChecker::$noParamOutContractCache on base method when unannotated sibling runs first', function () {
        $val1 = -10;
        $unannotated = new SiblingCachePoisoningParamOutUnannotatedChild();
        $unannotated->mutate($val1);
        expect($val1)->toBe(-10);

        expect(ParamOutChecker::$noParamOutContractCache)->not()->toHaveKey(SiblingCachePoisoningParamOutBase::class . '::mutate');

        $val2 = -10;
        $strict = new SiblingCachePoisoningParamOutStrictChild();

        expect(function () use ($strict, &$val2) {
            $strict->mutate($val2);
        })->toThrow(TypeError::class, 'positive-int');
    });

    test('does not poison SelfOutChecker::$noSelfOutContractCache on base method when unannotated sibling runs first', function () {
        $unannotated = new SiblingCachePoisoningSelfOutUnannotatedChild();
        $unannotated->transition();

        expect(SelfOutChecker::$noSelfOutContractCache)->not()->toHaveKey(SiblingCachePoisoningSelfOutBase::class . '::transition');

        /** @var SiblingCachePoisoningSelfOutStrictChild<'idle'> $strict */
        $strict = new SiblingCachePoisoningSelfOutStrictChild();
        $strict->transition();

        expect(TypePHP::getGenericType($strict, 'TState'))->toBe("'ready'");
    });

    test('does not poison static inherited method caches across sibling subclasses', function () {
        expect(SiblingCachePoisoningStaticUnannotatedChild::make(-5))->toBe(-5);

        expect(ParamChecker::$noParamContractCache)->not()->toHaveKey(SiblingCachePoisoningStaticBase::class . '::make')
            ->and(ReturnChecker::$noReturnContractCache)->not()->toHaveKey(SiblingCachePoisoningStaticBase::class . '::make')
        ;

        expect(fn () => SiblingCachePoisoningStaticStrictChild::make(-5))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('does not poison RuntimeTypeChecker::$hasMethodTemplatesCache across sibling subclasses', function () {
        expect(SiblingCachePoisoningTemplatedChild::echoValue('hello'))->toBe('hello');

        expect(RuntimeTypeChecker::$hasMethodTemplatesCache)->not()->toHaveKey(SiblingCachePoisoningTemplateBase::class . '::echoValue');

        expect(SiblingCachePoisoningNonTemplatedChild::echoValue(42))->toBe(42);
    });
});
