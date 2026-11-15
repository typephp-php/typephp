<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;
use TypePHP\Internal\Io\StreamWrapper;

describe('ArrayLiteralEvaluator Spread (...$x) Soundness (Red Phase)', function () {
    describe('1. Bare Identifiers (list, non-empty-array, non-empty-list)', function () {
        test('does not eliminate spread expressions against list, non-empty-array, or non-empty-list', function () {
            $spreadExpr = new Array_([
                new ArrayItem(new Variable('dynamic'), null, false, [], true),
            ]);

            expect(StaticTypeEvaluator::satisfies($spreadExpr, new IdentifierTypeNode('list')))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($spreadExpr, new IdentifierTypeNode('non-empty-array')))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($spreadExpr, new IdentifierTypeNode('non-empty-list')))->toBeFalse()
            ;
        });

        test('does not eliminate non-empty-list<array> when spreading an empty array [...[]]', function () {
            $spreadEmpty = new Array_([
                new ArrayItem(new Array_([]), null, false, [], true),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('non-empty-list'), [
                new IdentifierTypeNode('array'),
            ]);

            expect(StaticTypeEvaluator::satisfies($spreadEmpty, $type))->toBeFalse();
        });

        test('retains checkReturn and throws TypeError at runtime when spreading empty or associative array into non-empty-array / list', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

/**
 * @return non-empty-array
 */
function spreadIntoNonEmptyArray(array $items): array
{
    return [...$items];
}

/**
 * @return list
 */
function spreadIntoBareList(array $items): array
{
    return [...$items];
}

return [
    'empty' => spreadIntoNonEmptyArray([]),
];
PHP;

            $transformed = StreamWrapper::transformSource($source, __FILE__);
            expect(substr_count($transformed, 'RuntimeTypeChecker::checkReturn'))->toBe(2);

            $tempDir = sys_get_temp_dir() . '/typephp_spread_ident_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/spread_ident.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, 'non-empty-array')
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });

        test('throws TypeError at runtime when spreading associative array into bare list return', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

/**
 * @return list
 */
function spreadAssocIntoBareList(array $items): array
{
    return [...$items];
}

return spreadAssocIntoBareList(['a' => 1]);
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_spread_list_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/spread_list.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, 'must be of type list')
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });

    describe('2. Generic Lists & Typed Arrays (list<T>, T[], array<K, V>)', function () {
        test('does not eliminate list<int|array> when spreading associative array literal [...[\'a\' => 1]]', function () {
            $spreadAssoc = new Array_([
                new ArrayItem(
                    new Array_([new ArrayItem(new LNumber(1), new String_('a'))]),
                    null,
                    false,
                    [],
                    true
                ),
            ]);

            $listType = new GenericTypeNode(new IdentifierTypeNode('list'), [
                new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('array')]),
            ]);

            expect(StaticTypeEvaluator::satisfies($spreadAssoc, $listType))->toBeFalse();
        });

        test('does not eliminate array<int, int|array> when spreading associative array literal with string keys', function () {
            $spreadAssoc = new Array_([
                new ArrayItem(
                    new Array_([new ArrayItem(new LNumber(1), new String_('a'))]),
                    null,
                    false,
                    [],
                    true
                ),
            ]);

            $mapType = new GenericTypeNode(new IdentifierTypeNode('array'), [
                new IdentifierTypeNode('int'),
                new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('array')]),
            ]);

            expect(StaticTypeEvaluator::satisfies($spreadAssoc, $mapType))->toBeFalse();
        });

        test('does not eliminate ArrayTypeNode (array[]) when spreading non-matching elements', function () {
            $spreadBad = new Array_([
                new ArrayItem(
                    new Array_([new ArrayItem(new String_('not_an_int'), new String_('k'))]),
                    null,
                    false,
                    [],
                    true
                ),
            ]);

            $arrayType = new ArrayTypeNode(new IdentifierTypeNode('array'));

            expect(StaticTypeEvaluator::satisfies($spreadBad, $arrayType))->toBeFalse();
        });

        test('retains checkReturn and throws TypeError at runtime on spread into list<int|array> and array<int, int|array>', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

/**
 * @return list<int|array>
 */
function spreadAssocIntoTypedList(): array
{
    return [...['a' => 1]];
}

return spreadAssocIntoTypedList();
PHP;

            $transformed = StreamWrapper::transformSource($source, __FILE__);
            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');

            $tempDir = sys_get_temp_dir() . '/typephp_spread_typed_list_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/spread_typed_list.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, 'must be a list')
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });

    describe('3. Array Shapes (ArrayShapeNode)', function () {
        test('does not eliminate array{0: array<string, int>} when spreading [...[\'bad_key\' => [\'a\' => 1]]]', function () {
            $spreadShape = new Array_([
                new ArrayItem(
                    new Array_([
                        new ArrayItem(
                            new Array_([new ArrayItem(new LNumber(1), new String_('a'))]),
                            new String_('bad_key')
                        ),
                    ]),
                    null,
                    false,
                    [],
                    true
                ),
            ]);

            $shapeType = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(
                    new ConstExprIntegerNode('0'),
                    false,
                    new GenericTypeNode(new IdentifierTypeNode('array'), [
                        new IdentifierTypeNode('string'),
                        new IdentifierTypeNode('int'),
                    ])
                ),
            ]);

            expect(StaticTypeEvaluator::satisfies($spreadShape, $shapeType))->toBeFalse();
        });

        test('retains checkReturn and throws TypeError at runtime when spread violates array shape', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

/**
 * @return array{id: int}
 */
function spreadIntoShape(): array
{
    return [...['id' => 'not_an_int']];
}

return spreadIntoShape();
PHP;

            $transformed = StreamWrapper::transformSource($source, __FILE__);
            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');

            $tempDir = sys_get_temp_dir() . '/typephp_spread_shape_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/spread_shape.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, "['id'] must be of type int")
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });

    describe('4. Safe Positive Control: Mixed Concrete + Spread on non-empty-array', function () {
        test('still allows non-empty-array when at least one non-unpacked concrete item is present', function () {
            $mixed = new Array_([
                new ArrayItem(new LNumber(1)),
                new ArrayItem(new Variable('dynamic'), null, false, [], true),
            ]);

            expect(StaticTypeEvaluator::satisfies($mixed, new IdentifierTypeNode('non-empty-array')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($mixed, new IdentifierTypeNode('non-empty-list')))->toBeFalse()
            ;
        });
    });
});
