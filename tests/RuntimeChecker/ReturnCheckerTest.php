<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\ReturnChecker;
use TypePHP\Internal\Config;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Tests\Fixtures\Collections\ConcreteFileCollection;
use TypePHP\Tests\Fixtures\Collections\PluginConfiguration;
use TypePHP\Tests\Fixtures\Conditionals\ConditionalReturnService;
use TypePHP\Tests\Fixtures\Generics\DogConditionalBox;
use TypePHP\Tests\Fixtures\Services\AdminEntityFactory;
use TypePHP\Tests\Fixtures\Services\FluentService;
use TypePHP\Tests\Fixtures\Services\UserEntityFactory;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\MagicMethodFixture;
use TypePHP\Validator\TypeValidatorRegistry;

describe('ReturnChecker Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Basic Return Contracts', function () {
        test('accepts valid return values matching function contract', function () {
            $registry = new TypeValidatorRegistry();
            $target = UserService::class . '::find';

            $value = ['id' => 10, 'name' => 'Alice'];
            $result = ReturnChecker::checkReturn($target, $value, new UserService(), ['id' => 10], $registry, fn () => null);

            expect($result)->toBe($value);
        });

        test('returns ErrorMessage when return shape contract is violated', function () {
            $registry = new TypeValidatorRegistry();
            $target = UserService::class . '::find';

            $badValue = ['id' => -5, 'name' => 'Alice'];
            $result = ReturnChecker::checkReturn($target, $badValue, new UserService(), ['id' => -5], $registry, fn () => null);

            expect($result)->toBeInstanceOf(ErrorMessage::class)
                ->and($result->getMessage())->toContain("Return value['id'] must be of type positive-int")
            ;
        });

        test('returns value directly when returns checking is disabled in config', function () {
            Config::set(['returns' => false]);
            $registry = new TypeValidatorRegistry();
            $target = UserService::class . '::find';

            $badValue = ['id' => -99, 'name' => 'Alice'];
            $result = ReturnChecker::checkReturn($target, $badValue, new UserService(), ['id' => -99], $registry, fn () => null);

            expect($result)->toBe($badValue);
        });
    });

    describe('$this Identity Constraints', function () {
        test('accepts valid $this instance return', function () {
            $registry = new TypeValidatorRegistry();
            $target = FluentService::class . '::setValidSelf';
            $service = new FluentService();

            $result = ReturnChecker::checkReturn($target, $service, $service, [], $registry, fn () => null);

            expect($result)->toBe($service);
        });

        test('returns ErrorMessage when method returns a new instance instead of $this', function () {
            $registry = new TypeValidatorRegistry();
            $target = FluentService::class . '::setInvalidSelf';
            $service = new FluentService();

            $result = ReturnChecker::checkReturn($target, new FluentService(), $service, [], $registry, fn () => null);

            expect($result)->toBeInstanceOf(ErrorMessage::class)
                ->and($result->getMessage())->toContain('must be $this instance')
            ;
        });
    });

    describe('Late Static Binding (@return static)', function () {
        test('accepts returned instance matching late-static calling class', function () {
            $registry = new TypeValidatorRegistry();
            $target = UserEntityFactory::class . '::create';
            $instance = new UserEntityFactory();

            $result = ReturnChecker::checkReturn($target, $instance, UserEntityFactory::class, [], $registry, fn () => null);

            expect($result)->toBe($instance);
        });

        test('returns ErrorMessage when method returns sibling class instead of late-static calling class', function () {
            $registry = new TypeValidatorRegistry();
            $target = UserEntityFactory::class . '::createSibling';
            $siblingInstance = new AdminEntityFactory();

            $result = ReturnChecker::checkReturn($target, $siblingInstance, UserEntityFactory::class, [], $registry, fn () => null);

            expect($result)->toBeInstanceOf(ErrorMessage::class)
                ->and($result->getMessage())->toContain('must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory')
            ;
        });
    });

    describe('Parameter-Based Conditional Returns ($param is Target ? A : B)', function () {
        test('evaluates matching condition branch (positive-int)', function () {
            $registry = new TypeValidatorRegistry();
            $service = new ConditionalReturnService();
            $target = ConditionalReturnService::class . '::formatByParameter';

            $result = ReturnChecker::checkReturn($target, 42, $service, ['format' => 'int', 'value' => 42], $registry, fn () => null);
            expect($result)->toBe(42);

            $badResult = ReturnChecker::checkReturn($target, -10, $service, ['format' => 'int', 'value' => -10], $registry, fn () => null);
            expect($badResult)->toBeInstanceOf(ErrorMessage::class)
                ->and($badResult->getMessage())->toContain('positive-int')
            ;
        });

        test('evaluates fallback else branch (non-empty-string)', function () {
            $registry = new TypeValidatorRegistry();
            $service = new ConditionalReturnService();
            $target = ConditionalReturnService::class . '::formatByParameter';

            $result = ReturnChecker::checkReturn($target, 'valid_text', $service, ['format' => 'other', 'value' => 'valid_text'], $registry, fn () => null);
            expect($result)->toBe('valid_text');

            $badResult = ReturnChecker::checkReturn($target, '', $service, ['format' => 'other', 'value' => ''], $registry, fn () => null);
            expect($badResult)->toBeInstanceOf(ErrorMessage::class)
                ->and($badResult->getMessage())->toContain('non-empty-string')
            ;
        });

        test('evaluates negated parameter conditions ($flag is not true)', function () {
            $registry = new TypeValidatorRegistry();
            $service = new ConditionalReturnService();
            $target = ConditionalReturnService::class . '::formatByNegation';

            $result = ReturnChecker::checkReturn($target, 'active', $service, ['flag' => false, 'value' => 'active'], $registry, fn () => null);
            expect($result)->toBe('active');

            $resultInt = ReturnChecker::checkReturn($target, 100, $service, ['flag' => true, 'value' => 100], $registry, fn () => null);
            expect($resultInt)->toBe(100);
        });
    });

    describe('Template-Based Conditional Returns (T is Target ? A : B)', function () {
        test('evaluates conditional return based on bound generic template T', function () {
            $registry = new TypeValidatorRegistry();
            $box = new DogConditionalBox();
            $target = DogConditionalBox::class . '::processInput';

            $result = ReturnChecker::checkReturn($target, 100, $box, ['input' => 100], $registry, fn () => null);
            expect($result)->toBe(100);

            $badResult = ReturnChecker::checkReturn($target, -50, $box, ['input' => -50], $registry, fn () => null);
            expect($badResult)->toBeInstanceOf(ErrorMessage::class)
                ->and($badResult->getMessage())->toContain('positive-int')
            ;
        });
    });

    describe('Dynamic Magic @method Returns via __call', function () {
        test('validates return shape on dynamic @method calls', function () {
            $registry = new TypeValidatorRegistry();
            $fixture = new MagicMethodFixture();
            $target = MagicMethodFixture::class . '::__call';

            $validPayload = ['id' => 10, 'tags' => ['php', 'typephp']];
            $result = ReturnChecker::checkReturn($target, $validPayload, $fixture, [
                'name' => 'buildPayload',
                'arguments' => [[10], 'active'],
            ], $registry, fn () => null);

            expect($result)->toBe($validPayload);
        });

        test('returns ErrorMessage when dynamic @method return violates shape contract', function () {
            $registry = new TypeValidatorRegistry();
            $fixture = new MagicMethodFixture();
            $target = MagicMethodFixture::class . '::__call';

            $badPayload = ['id' => -1, 'tags' => ['php']];
            $result = ReturnChecker::checkReturn($target, $badPayload, $fixture, [
                'name' => 'buildPayload',
                'arguments' => [[10], 'active'],
            ], $registry, fn () => null);

            expect($result)->toBeInstanceOf(ErrorMessage::class)
                ->and($result->getMessage())->toContain("Return value['id'] must be of type positive-int")
            ;
        });
    });

    describe('Traversable & Iterable Return Handling', function () {
        test('does not wrap concrete collection in IteratorProxy on return', function () {
            $registry = new TypeValidatorRegistry();
            $config = new PluginConfiguration();
            $target = PluginConfiguration::class . '::getStyleFilesWithDocblock';

            $files = new ConcreteFileCollection();
            $wrappedCalled = false;

            $result = ReturnChecker::checkReturn(
                $target,
                $files,
                $config,
                [],
                $registry,
                function () use (&$wrappedCalled) {
                    $wrappedCalled = true;

                    return null;
                }
            );

            expect($result)->toBe($files)
                ->and($wrappedCalled)->toBeFalse();
        });
    });
});
