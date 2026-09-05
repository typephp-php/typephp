<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\ConstKeyContainer;
use TypePHP\Tests\Fixtures\Types\MissingConstKeyContainer;

trait TraitWithConstantFixture
{
    public const TRAIT_KEY = 'trait_action';
}

class TraitConstContainer
{
    /**
     * @param array{TraitWithConstantFixture::TRAIT_KEY: positive-int} $payload
     */
    public function process(array $payload): bool
    {
        return true;
    }
}

describe('Class Constant Keys in Array Shapes (array{self::CONST_KEY: T})', function () {

    test('resolves self::CONST_KEY inside array shape to string key user_id', function () {
        $container = new ConstKeyContainer();

        expect($container->process([
            'user_id' => 42,
            'user_role' => 'admin',
        ]))->toBeTrue();
    });

    test('throws TypeError when array shape item with class constant key violates type contract', function () {
        $container = new ConstKeyContainer();

        $manualCheck = \TypePHP\Internal\RuntimeTypeChecker::setupScope(
            'TypePHP\Tests\Fixtures\Types\ConstKeyContainer::process',
            ['payload' => ['user_id' => -5, 'user_role' => 'admin']],
            $container
        );
        fwrite(STDERR, "[DEBUG] setupScope returned class: " . (is_object($manualCheck) ? get_class($manualCheck) : var_export($manualCheck, true)) . "\n");
        fwrite(STDERR, "[DEBUG] instanceof ErrorMessage: " . var_export($manualCheck instanceof \TypePHP\Internal\Diagnostic\ErrorMessage, true) . "\n");

        expect(fn() => $container->process([
            'user_id' => -5,
            'user_role' => 'admin',
        ]))->toThrow(TypeError::class, "['user_id'] must be of type positive-int");
    });

    test('throws TypeError when array shape references a non-existent class constant key', function () {
        $container = new MissingConstKeyContainer();

        expect(fn() => $container->process(['user_id' => 42]))
            ->toThrow(TypeError::class, "is missing required key 'self::NON_EXISTENT_KEY'");
    });

    test('resolves PHP 8.2+ trait constants inside array shapes', function () {
        if (PHP_VERSION_ID < 80200) {
            expect(true)->toBeTrue();

            return;
        }

        $container = new TraitConstContainer();

        expect($container->process(['trait_action' => 42]))->toBeTrue();
    });
});
