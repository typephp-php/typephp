<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use TypePHP\Internal\Io\StreamWrapper;

describe('Compile-Time Return Elimination via Parameter Type Tracking', function () {
    test('eliminates checkReturn when method returns an array shape containing unmutated validated parameters', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Benchmark;

class OrderService
{
    /**
     * @param positive-int $orderId
     * @param non-empty-string $customerName
     * @param array{role: 'admin'|'user', active: bool} $meta
     * @param list<positive-int> $itemIds
     *
     * @return array{order_id: positive-int, status: 'completed'}
     */
    public function processOrder(int $orderId, string $customerName, array $meta, array $itemIds): array
    {
        return [
            'order_id' => $orderId,
            'status' => 'completed',
        ];
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_order_service.php');

        expect($transformed)->toContain('RuntimeTypeChecker::setupScope')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain("'order_id' => \$orderId")
            ->and($transformed)->toContain("'status' => 'completed'")
        ;
    });

    test('eliminates checkReturn when returning an unmutated parameter directly to a compatible return type', function () {
        $source = <<<'PHP'
<?php
/**
 * @param positive-int $id
 * @return int
 */
function toInt(int $id): int
{
    return $id;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_direct_param_return.php');

        expect($transformed)->toContain('RuntimeTypeChecker::setupScope')
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ->and($transformed)->toContain('return $id;')
        ;
    });

    test('retains checkReturn when parameter is reassigned inside the function body', function () {
        $source = <<<'PHP'
<?php
/**
 * @param positive-int $id
 * @return positive-int
 */
function mutateParam(int $id): int
{
    $id = -5;
    return $id;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_mutated_param.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('retains checkReturn when parameter is decremented inside the function body', function () {
        $source = <<<'PHP'
<?php
/**
 * @param positive-int $id
 * @return positive-int
 */
function decrementParam(int $id): int
{
    $id--;
    return $id;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_dec_param.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('retains checkReturn when parameter is passed as an argument to a function call', function () {
        $source = <<<'PHP'
<?php
/**
 * @param positive-int $id
 * @return positive-int
 */
function withCall(int $id): int
{
    doSomething($id);
    return $id;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_call_param.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('retains checkReturn when parameter type does not satisfy the return contract (contravariant/wider)', function () {
        $source = <<<'PHP'
<?php
/**
 * @param int $id
 * @return positive-int
 */
function widerParam(int $id): int
{
    return $id;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_wider_param.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('retains checkReturn when parameter was declared by reference in method signature', function () {
        $source = <<<'PHP'
<?php
/**
 * @param positive-int &$id
 * @return positive-int
 */
function byRefParam(int &$id): int
{
    return $id;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_byref_param.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
    });
});
