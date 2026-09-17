<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Checker;

use TypePHP\Internal\Checker\SelfOutChecker;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\TypePHP;

/**
 * Fixture: State machine transitioning between literal states
 *
 * @template TState of 'unauthenticated'|'authenticated'
 */
class FixtureSelfOutCheckerState
{
    /**
     * @self-out self<'authenticated'>
     */
    public function login(): void
    {
    }

    /**
     * @self-out self<'unauthenticated'>
     */
    public function logout(): void
    {
    }

    public function noSelfOutMethod(): void
    {
    }
}

/**
 * Fixture: Accumulating new generic types into existing template
 *
 * @template T
 */
class FixtureSelfOutAccumulator
{
    /**
     * @self-out self<T|\TypePHP\Tests\Fixtures\Domain\Cat>
     */
    public function addCat(): void
    {
    }
}

/**
 * Fixture: Conditional self-out based on method arguments
 *
 * @template TRole of 'admin'|'guest'
 */
class FixtureConditionalSelfOut
{
    /**
     * @param bool $asAdmin
     *
     * @self-out ($asAdmin is true ? self<'admin'> : self<'guest'>)
     */
    public function switchRole(bool $asAdmin): void
    {
    }
}

/**
 * Fixture: Parent class with self-out for inheritance testing
 *
 * @template T
 */
class FixtureSelfOutParent
{
    /**
     * @self-out self<'updated_state'>
     */
    public function triggerUpdate(): void
    {
    }
}

class FixtureSelfOutChild extends FixtureSelfOutParent
{
}

describe('SelfOutChecker Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        SelfOutChecker::reset();
    });

    afterEach(function () {
        Config::reset();
        SelfOutChecker::reset();
    });

    describe('Generic State Transitions', function () {
        test('updates object generic template binding in TemplateManager after method execution', function () {
            $registry = new TypeValidatorRegistry();
            $obj = new FixtureSelfOutCheckerState();

            TemplateManager::bindInstance($obj, FixtureSelfOutCheckerState::class . "<'unauthenticated'>");
            expect(TypePHP::getGenericType($obj))->toBe("'unauthenticated'");

            SelfOutChecker::checkSelfOut(
                FixtureSelfOutCheckerState::class . '::login',
                $obj,
                [],
                $registry
            );

            expect(TypePHP::getGenericType($obj))->toBe("'authenticated'");
        });

        test('transitions generic state back upon executing logout method', function () {
            $registry = new TypeValidatorRegistry();
            $obj = new FixtureSelfOutCheckerState();

            TemplateManager::bindInstance($obj, FixtureSelfOutCheckerState::class . "<'authenticated'>");

            SelfOutChecker::checkSelfOut(
                FixtureSelfOutCheckerState::class . '::logout',
                $obj,
                [],
                $registry
            );

            expect(TypePHP::getGenericType($obj))->toBe("'unauthenticated'");
        });

        test('substitutes current bound templates in dynamic self-out union accumulation', function () {
            $registry = new TypeValidatorRegistry();
            $acc = new FixtureSelfOutAccumulator();

            TemplateManager::bindInstance($acc, FixtureSelfOutAccumulator::class . '<' . Dog::class . '>');

            SelfOutChecker::checkSelfOut(
                FixtureSelfOutAccumulator::class . '::addCat',
                $acc,
                [],
                $registry
            );

            expect(TypePHP::getGenericType($acc))->toBe('(' . Dog::class . ' | ' . Cat::class . ')');
        });
    });

    describe('Conditional Self-Out Contracts', function () {
        test('evaluates parameter-based conditional branches in self-out', function () {
            $registry = new TypeValidatorRegistry();
            $cond = new FixtureConditionalSelfOut();

            TemplateManager::bindInstance($cond, FixtureConditionalSelfOut::class . "<'guest'>");

            SelfOutChecker::checkSelfOut(
                FixtureConditionalSelfOut::class . '::switchRole',
                $cond,
                ['asAdmin' => true],
                $registry
            );
            expect(TypePHP::getGenericType($cond))->toBe("'admin'");

            SelfOutChecker::checkSelfOut(
                FixtureConditionalSelfOut::class . '::switchRole',
                $cond,
                ['asAdmin' => false],
                $registry
            );
            expect(TypePHP::getGenericType($cond))->toBe("'guest'");
        });
    });

    describe('Inherited Self-Out Contracts', function () {
        test('resolves inherited self-out contracts on child class instances', function () {
            $registry = new TypeValidatorRegistry();
            $child = new FixtureSelfOutChild();

            TemplateManager::bindInstance($child, FixtureSelfOutChild::class . "<'initial'>");

            SelfOutChecker::checkSelfOut(
                FixtureSelfOutParent::class . '::triggerUpdate',
                $child,
                [],
                $registry
            );

            expect(TypePHP::getGenericType($child))->toBe("'updated_state'");
        });
    });

    describe('Caching & Optimization', function () {
        test('populates noSelfOutContractCache for methods without self-out annotations', function () {
            $registry = new TypeValidatorRegistry();
            $obj = new FixtureSelfOutCheckerState();

            $target = FixtureSelfOutCheckerState::class . '::noSelfOutMethod';

            expect(SelfOutChecker::$noSelfOutContractCache)->not()->toHaveKey($target);

            SelfOutChecker::checkSelfOut($target, $obj, [], $registry);

            expect(SelfOutChecker::$noSelfOutContractCache)->toHaveKey($target);
        });

        test('resets noSelfOutContractCache on reset()', function () {
            SelfOutChecker::$noSelfOutContractCache['Dummy::method'] = true;
            expect(SelfOutChecker::$noSelfOutContractCache)->not()->toBeEmpty();

            SelfOutChecker::reset();

            expect(SelfOutChecker::$noSelfOutContractCache)->toBeEmpty();
        });
    });

    describe('Configuration Toggles', function () {
        test('bypasses state transitions when self_out config toggle is disabled', function () {
            Config::set(['self_out' => false]);

            $registry = new TypeValidatorRegistry();
            $obj = new FixtureSelfOutCheckerState();

            TemplateManager::bindInstance($obj, FixtureSelfOutCheckerState::class . "<'unauthenticated'>");

            SelfOutChecker::checkSelfOut(
                FixtureSelfOutCheckerState::class . '::login',
                $obj,
                [],
                $registry
            );

            expect(TypePHP::getGenericType($obj))->toBe("'unauthenticated'");
        });

        test('bypasses state transitions when global master switch is disabled', function () {
            Config::set(['enabled' => false]);

            $registry = new TypeValidatorRegistry();
            $obj = new FixtureSelfOutCheckerState();

            TemplateManager::bindInstance($obj, FixtureSelfOutCheckerState::class . "<'unauthenticated'>");

            SelfOutChecker::checkSelfOut(
                FixtureSelfOutCheckerState::class . '::login',
                $obj,
                [],
                $registry
            );

            expect(TypePHP::getGenericType($obj))->toBe("'unauthenticated'");
        });
    });
});
