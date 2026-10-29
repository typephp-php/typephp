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
            ->and($transformed)->toContain('return $this;')
        ;
    });
});

describe('Immediate Predecessor Local Data-Flow Elimination ($x = <proven>; return $x;)', function () {
    test('eliminates checkReturn when returned variable was immediately assigned a proven string literal', function () {
        $source = <<<'PHP'
<?php
/** @return non-empty-string */
function getPredecessorName(): string
{
    $name = 'Alice';
    return $name;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_flow_name.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain('return $name;')
        ;
    });

    test('eliminates checkReturn when returned variable was immediately assigned a proven list literal', function () {
        $source = <<<'PHP'
<?php
class ResponseService
{
    /** @return list<positive-int> */
    private function getIds(): array
    {
        $ids = [1, 2, 3];
        return $ids;
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_flow_list.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain('return $ids;')
        ;
    });

    test('eliminates checkReturn inside an if-block when variable is immediately assigned and returned', function () {
        $source = <<<'PHP'
<?php
/** @return non-empty-string */
function getStatus(bool $flag): string
{
    if ($flag) {
        $msg = 'active';
        return $msg;
    }
    return 'fallback';
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_flow_if.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain('return $msg;')
            ->and($transformed)->toContain("return 'fallback';")
        ;
    });

    test('retains checkReturn when immediately assigned variable violates the return contract', function () {
        $source = <<<'PHP'
<?php
/** @return positive-int */
function getBadCode(): int
{
    $code = -10;
    return $code;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_flow_bad.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('retains checkReturn when intervening statements exist between assignment and return', function () {
        $source = <<<'PHP'
<?php
/** @return non-empty-string */
function getWithInterveningCall(): string
{
    $val = 'hello';
    someOtherFunction();
    return $val;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_flow_intervene.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });
});

describe('Generator & Iterable Compile-Time Check Elimination', function () {
    test('completely eliminates checkYield, checkSend, and checkGeneratorReturn on proven 2-arg Generator', function () {
        $source = <<<'PHP'
<?php
/** @return Generator<string, positive-int> */
function streamProvenConstants(): Generator
{
    yield 'first' => 10;
    yield 'second' => 20;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_gen_proven.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkYield')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkSend')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn')
        ;
    });

    test('eliminates checkYield on string concatenation and interpolation for non-empty-string', function () {
        $source = <<<'PHP'
<?php
/** @return Generator<non-empty-string, non-empty-string> */
function streamUserLabels(int $id, string $suffix): Generator
{
    yield 'user_' . $id => "label_{$suffix}";
    $computed = 'prefix_' . $suffix;
    yield 'final' => $computed;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_gen_concat.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkYield')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkSend')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn')
        ;
    });

    test('eliminates checkSend and checkGeneratorReturn while retaining checkYield when yielded value is dynamic', function () {
        $source = <<<'PHP'
<?php
/** @return Generator<string, positive-int> */
function streamDynamicValue(int $dynamicVal): Generator
{
    yield 'item' => $dynamicVal;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_gen_partial.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkYield')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkSend')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn')
        ;
    });

    test('eliminates checkYield while retaining checkSend when TSend is constrained and yielded literal is proven', function () {
        $source = <<<'PHP'
<?php
/** @return Generator<string, positive-int, non-empty-string, void> */
function streamInteractiveProven(): Generator
{
    $input = yield 'prompt' => 1;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_gen_send_only.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkYield')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkSend')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn')
        ;
    });

    test('eliminates wrapIterable on yield from proven array literal and retains on unproven array', function () {
        $provenSource = <<<'PHP'
<?php
/** @return Generator<string, non-empty-string> */
function streamYieldFromProven(int $id): Generator
{
    yield from ['user_' . $id => 'role_' . $id];
}
PHP;

        $transformedProven = StreamWrapper::transformSource($provenSource, 'test_yield_from_proven.php');
        expect($transformedProven)->not()->toContain('RuntimeTypeChecker::wrapIterable');

        $unprovenSource = <<<'PHP'
<?php
/** @return Generator<string, positive-int> */
function streamYieldFromInvalid(): Generator
{
    yield from ['a' => 10, 'b' => -5];
}
PHP;

        $transformedUnproven = StreamWrapper::transformSource($unprovenSource, 'test_yield_from_unproven.php');
        expect($transformedUnproven)->toContain('RuntimeTypeChecker::wrapIterable');
    });

    test('eliminates checkGeneratorReturn on proven TReturn and retains when TReturn is violated', function () {
        $provenReturn = <<<'PHP'
<?php
/** @return Generator<int, string, mixed, non-empty-string> */
function genProvenReturn(string $tag): Generator
{
    yield 0 => 'step';
    return 'done_' . $tag;
}
PHP;

        $transformedProven = StreamWrapper::transformSource($provenReturn, 'test_gen_ret_proven.php');
        expect($transformedProven)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn');

        $invalidReturn = <<<'PHP'
<?php
/** @return Generator<int, string, mixed, positive-int> */
function genInvalidReturn(): Generator
{
    yield 0 => 'step';
    return -5;
}
PHP;

        $transformedInvalid = StreamWrapper::transformSource($invalidReturn, 'test_gen_ret_invalid.php');
        expect($transformedInvalid)->toContain('RuntimeTypeChecker::checkGeneratorReturn');
    });

    test('completely eliminates checkYield, checkSend, and checkGeneratorReturn on proven 4-arg Generator with mixed TSend', function () {
        $source = <<<'PHP'
<?php
/** @return Generator<int, positive-int, mixed, string> */
function counterStreamMixed(): Generator
{
    yield 0 => 10;
    yield 1 => 20;

    return 'done';
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_gen_mixed_send.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkYield')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkSend')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn')
            ->and($transformed)->toContain('yield 0 => 10;')
            ->and($transformed)->toContain('yield 1 => 20;')
            ->and($transformed)->toContain("return 'done';")
        ;
    });

    test('retains checkSend on 4-arg Generator when TSend is void to strictly police sent values', function () {
        $source = <<<'PHP'
<?php
/** @return Generator<int, positive-int, void, string> */
function counterStreamVoid(): Generator
{
    yield 0 => 10;
    return 'done';
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_gen_void_send.php');

        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkYield')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkSend')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn')
        ;
    });
});
