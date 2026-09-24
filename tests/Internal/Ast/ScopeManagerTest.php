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
});
