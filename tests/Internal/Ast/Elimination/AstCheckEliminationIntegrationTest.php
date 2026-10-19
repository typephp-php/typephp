<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use TypePHP\Internal\Io\StreamWrapper;

describe('AST Check Elimination End-to-End Integration Tests', function () {
    test('eliminates checkReturn on standalone function returning a proven string literal', function () {
        $source = <<<'PHP'
<?php
/** @return non-empty-string */
function getLabel(): string
{
    return 'active_label';
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_return_literal.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain("return 'active_label';")
        ;
    });

    test('retains checkReturn on standalone function returning an unproven/violating integer', function () {
        $source = <<<'PHP'
<?php
/** @return positive-int */
function getInvalid(): int
{
    return -5;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_return_invalid.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('eliminates checkReturn on private method returning sequential list literal', function () {
        $source = <<<'PHP'
<?php
class Service
{
    /** @return list<positive-int> */
    private function getIds(): array
    {
        return [1, 2, 3];
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_list_return.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain('return [1, 2, 3];')
        ;
    });

    test('retains checkReturn on method returning list when element violates contract', function () {
        $source = <<<'PHP'
<?php
class Service
{
    /** @return list<positive-int> */
    private function getBadIds(): array
    {
        return [1, -2, 3];
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_bad_list.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('eliminates checkReturn when returning empty array for list<T>', function () {
        $source = <<<'PHP'
<?php
/** @return list<int> */
function getEmpty(): array
{
    return [];
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_empty_list.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain('return [];')
        ;
    });

    test('retains checkReturn when returning empty array for non-empty-list<T>', function () {
        $source = <<<'PHP'
<?php
/** @return non-empty-list<int> */
function getEmptyFails(): array
{
    return [];
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_empty_non_empty.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('eliminates property default assertion in constructor when default is statically proven', function () {
        $source = <<<'PHP'
<?php
class UserConfig
{
    /** @var positive-int */
    public int $retries = 3;

    /** @var non-empty-string */
    public string $theme = 'dark';
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_property_defaults.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkProperty')
            ->and($transformed)->not()->toContain('__construct')
        ;
    });

    test('retains property default assertion when default violates contract', function () {
        $source = <<<'PHP'
<?php
class BadConfig
{
    /** @var positive-int */
    public int $retries = -1;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_bad_prop.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkProperty')
            ->and($transformed)->toContain('__construct')
        ;
    });

    test('eliminates checkVariable on inline @var assignment when expression is statically proven', function () {
        $source = <<<'PHP'
<?php
/** @var positive-int $x */
$x = 42;
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_var_assign.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkVariable')
            ->and($transformed)->toContain('$x = 42;')
        ;
    });

    test('retains checkVariable on inline @var assignment when expression violates contract', function () {
        $source = <<<'PHP'
<?php
/** @var positive-int $x */
$x = -5;
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_bad_var.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkVariable');
    });

    test('eliminates checkReturn on return $this in standalone class', function () {
        $source = <<<'PHP'
<?php
class FluentService
{
    /** @return $this */
    public function setFlag(): self
    {
        return $this;
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_this_return.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain('return $this;');
    });
});
