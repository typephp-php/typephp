<?php

declare(strict_types=1);

use PhpParser\Node;
use TypePHP\Internal\Ast\ScopeManager;

describe('ScopeManager Unit Tests', function () {
    test('manages scoped variable stack frames', function () {
        $manager = new ScopeManager();

        $manager->pushScope();
        $manager->extractVarDocblock('/** @var positive-int $age */');

        expect($manager->getVarTypeFromScope('age'))->toBe('positive-int');

        $manager->popScope();
        expect($manager->getVarTypeFromScope('age'))->toBeNull();
    });

    test('resolves variables from outer scope if not overridden in inner scope', function () {
        $manager = new ScopeManager();

        $manager->pushScope();
        $manager->extractVarDocblock('/** @var positive-int $globalId */');

        $manager->pushScope();
        expect($manager->getVarTypeFromScope('globalId'))->toBe('positive-int');

        $manager->popScope();
        $manager->popScope();
    });

    test('infers variable name from assignment expression if unnamed in docblock', function () {
        $manager = new ScopeManager();
        $manager->pushScope();

        $assign = new Node\Expr\Assign(
            new Node\Expr\Variable('username'),
            new Node\Scalar\String_('Alice')
        );

        $manager->extractVarDocblock('/** @var non-empty-string */', $assign);

        expect($manager->getVarTypeFromScope('username'))->toBe('non-empty-string');
    });

    test('prioritizes @phpstan-var over @var in scoped variable extractions', function () {
        $manager = new ScopeManager();
        $manager->pushScope();

        $doc = <<<'DOC'
/**
 * @var mixed $count
 * @phpstan-var positive-int $count
 */
DOC;
        $manager->extractVarDocblock($doc);

        expect($manager->getVarTypeFromScope('count'))->toBe('positive-int');
    });

    test('prioritizes @psalm-var over @var in scoped variable extractions when @phpstan-var is absent', function () {
        $manager = new ScopeManager();
        $manager->pushScope();

        $doc = <<<'DOC'
/**
 * @var mixed $tag
 * @psalm-var non-empty-string $tag
 */
DOC;
        $manager->extractVarDocblock($doc);

        expect($manager->getVarTypeFromScope('tag'))->toBe('non-empty-string');
    });

    test('infers variable name from standalone variable expression when unnamed in docblock', function () {
        $manager = new ScopeManager();
        $manager->pushScope();

        $varExpr = new Node\Expr\Variable('activeUser');

        $manager->extractVarDocblock('/** @var non-empty-string */', $varExpr);

        expect($manager->getVarTypeFromScope('activeUser'))->toBe('non-empty-string');
    });

    test('gracefully catches and ignores malformed docblocks that throw parser exceptions', function () {
        $manager = new ScopeManager();
        $manager->pushScope();

        $manager->extractVarDocblock('invalid docblock text without phpdoc comment markers @var int $test');

        expect($manager->getVarTypeFromScope('test'))->toBeNull();
    });

    describe('Inferred Variable Type Stack (registerInferredVarType & getInferredVarType)', function () {
        test('registers and resolves inferred variable types while keeping explicit getVarTypeFromScope null', function () {
            $manager = new ScopeManager();
            $manager->pushScope();

            $manager->registerInferredVarType('box', 'App\Models\Box');

            expect($manager->getInferredVarType('box'))->toBe('App\Models\Box')
                ->and($manager->getVarTypeFromScope('box'))->toBeNull()
            ;
        });

        test('resolves inferred variable types across nested scopes and restores on popScope', function () {
            $manager = new ScopeManager();
            $manager->pushScope();
            $manager->registerInferredVarType('entity', 'App\Models\Order');

            $manager->pushScope();
            expect($manager->getInferredVarType('entity'))->toBe('App\Models\Order');

            $manager->registerInferredVarType('entity', 'App\Models\Invoice');
            expect($manager->getInferredVarType('entity'))->toBe('App\Models\Invoice');

            $manager->popScope();
            expect($manager->getInferredVarType('entity'))->toBe('App\Models\Order');

            $manager->popScope();
            expect($manager->getInferredVarType('entity'))->toBeNull();
        });

        test('unregisters inferred variable type on demand with unregisterInferredVarType', function () {
            $manager = new ScopeManager();
            $manager->pushScope();
            $manager->registerInferredVarType('temp', 'App\TempClass');

            expect($manager->getInferredVarType('temp'))->toBe('App\TempClass');

            $manager->unregisterInferredVarType('temp');
            expect($manager->getInferredVarType('temp'))->toBeNull();
        });

        test('unregisterVarType clears both explicit and inferred types (e.g. on unset)', function () {
            $manager = new ScopeManager();
            $manager->pushScope();
            $manager->registerVarType('item', 'positive-int');
            $manager->registerInferredVarType('item', 'App\ItemClass');

            expect($manager->getVarTypeFromScope('item'))->toBe('positive-int')
                ->and($manager->getInferredVarType('item'))->toBe('App\ItemClass')
            ;

            $manager->unregisterVarType('item');

            expect($manager->getVarTypeFromScope('item'))->toBeNull()
                ->and($manager->getInferredVarType('item'))->toBeNull()
            ;
        });

        test('stops resolving inferred variable types at isolated scope boundaries', function () {
            $manager = new ScopeManager();
            $manager->pushScope();
            $manager->registerInferredVarType('outerVar', 'App\Outer');

            $manager->pushScope(isIsolated: true);

            expect($manager->getInferredVarType('outerVar'))->toBeNull();

            $manager->popScope();
            expect($manager->getInferredVarType('outerVar'))->toBe('App\Outer');
        });
    });
});
