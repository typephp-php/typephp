<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

if (PHP_VERSION_ID < 80500) {
    return;
}

require_once __DIR__ . '/../../Fixtures/Php85/CloneWithFunctionalFixtures.php';

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericBox;
use TypePHP\Tests\Fixtures\Php85\CloneWithModelFixture;
use TypePHP\Tests\Fixtures\Php85\CloneWithReadonlyOrder;
use TypePHP\TypePHP;

describe('PHP 8.5 "Clone With" Syntax Support (TDD)', function () {
    describe('1. AST Transformation (nikic/php-parser FuncCall interception)', function () {
        test('transforms PHP 8.5 clone($obj, [...]) function call into RuntimeTypeChecker::cloneWith wrapper', function () {
            $source = <<<'PHP'
<?php
$copy = clone($order, ['score' => 20]);
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_clone_with.php');

            expect($transformed)->toContain('RuntimeTypeChecker::cloneWith')
                ->and($transformed)->toContain('[\'score\' => 20]')
            ;
        });

        test('transforms single-argument clone($obj) into RuntimeTypeChecker::cloneInstance wrapper', function () {
            $source = <<<'PHP'
<?php
$copy = clone($order);
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_clone_single.php');

            expect($transformed)->toContain('RuntimeTypeChecker::cloneInstance');
        });
    });

    describe('2. Runtime Generic Type Preservation via WeakMap', function () {
        test('preserves generic template bindings when cloning an object with cloneWith helper', function () {
            /** @var GenericBox<Dog> $original */
            $original = new GenericBox();
            $original->set(new Dog());

            $cloned = clone $original;

            RuntimeTypeChecker::cloneWith($cloned, $original);

            expect(TypePHP::getGenericType($cloned))->toBe(Dog::class);

            $cloned->set(new Dog());
            expect($cloned->item)->toBeInstanceOf(Dog::class);


            expect(fn () => $cloned->set(new Car()))
                ->toThrow(TypeError::class, Dog::class)
            ;
        });
    });

    describe('3. Overridden Property Validation on Clone', function () {
        test('allows valid property overrides on cloned instances without throwing', function () {
            $user = new CloneWithModelFixture();
            $cloned = clone $user;

            $result = RuntimeTypeChecker::cloneWith(
                $cloned,
                $user,
                ['score' => 50, 'name' => 'Bob'],
                __FILE__,
                __LINE__
            );

            expect($result)->toBe($cloned);
        });

        test('throws TypeError when overridden property violates positive-int contract', function () {
            $user = new CloneWithModelFixture();
            $cloned = clone $user;

            expect(function () use ($cloned, $user) {
                RuntimeTypeChecker::cloneWith(
                    $cloned,
                    $user,
                    ['score' => -5],
                    __FILE__,
                    __LINE__
                );
            })->toThrow(TypeError::class, 'positive-int');
        });

        test('throws TypeError when overridden property violates non-empty-string contract', function () {
            $user = new CloneWithModelFixture();
            $cloned = clone $user;

            expect(function () use ($cloned, $user) {
                RuntimeTypeChecker::cloneWith(
                    $cloned,
                    $user,
                    ['name' => ''],
                    __FILE__,
                    __LINE__
                );
            })->toThrow(TypeError::class, 'non-empty-string');
        });

        test('validates overridden properties on readonly classes during cloneWith', function () {
            $order = new CloneWithReadonlyOrder(100, 'SKU-ORIGINAL');
            $cloned = clone $order;

            expect(function () use ($cloned, $order) {
                RuntimeTypeChecker::cloneWith(
                    $cloned,
                    $order,
                    ['id' => -999],
                    __FILE__,
                    __LINE__
                );
            })->toThrow(TypeError::class, 'positive-int');
        });
    });

    describe('4. Native PHP 8.5 Execution End-to-End', function () {
        test('executes native clone($obj, [...]) through stream wrapper and enforces type contracts', function () {
            $source = <<<'PHP'
<?php

$order = new \TypePHP\Tests\Fixtures\Php85\CloneWithReadonlyOrder(42, 'SKU-VALID');
$validOrder = $order->withId(100);

$user = new \TypePHP\Tests\Fixtures\Php85\CloneWithModelFixture();
$validUser = clone($user, ['score' => 50]);

$badUser = clone($user, ['score' => -10]);
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_php85_clone_' . uniqid();
            mkdir($tempDir, 0777, true);
            $script = $tempDir . '/run_clone.php';
            file_put_contents($script, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $script)
                    ->toThrow(TypeError::class, 'positive-int')
                ;
            } finally {
                @unlink($script);
                @rmdir($tempDir);
            }
        });
    });
});