<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Ast\Elimination\EliminationSafetyPolicy;
use TypePHP\Internal\Ast\Elimination\Evaluator\GeneratorEvaluator;

describe('GeneratorEvaluator & EliminationSafetyPolicy Unit Tests', function () {
    describe('extractContract()', function () {
        test('extracts 1, 2, 3, and 4 generic arguments from Generator and iterable types', function () {
            $oneArg = new GenericTypeNode(new IdentifierTypeNode('Generator'), [
                new IdentifierTypeNode('positive-int'),
            ]);
            $contract1 = GeneratorEvaluator::extractContract($oneArg);
            expect($contract1)->not()->toBeNull()
                ->and($contract1['key'])->toBeNull()
                ->and((string) $contract1['value'])->toBe('positive-int')
                ->and($contract1['send'])->toBeNull()
                ->and($contract1['return'])->toBeNull()
            ;

            $twoArgs = new GenericTypeNode(new IdentifierTypeNode('iterable'), [
                new IdentifierTypeNode('non-empty-string'),
                new IdentifierTypeNode('positive-int'),
            ]);
            $contract2 = GeneratorEvaluator::extractContract($twoArgs);
            expect($contract2)->not()->toBeNull()
                ->and((string) $contract2['key'])->toBe('non-empty-string')
                ->and((string) $contract2['value'])->toBe('positive-int')
                ->and($contract2['send'])->toBeNull()
                ->and($contract2['return'])->toBeNull()
            ;

            $fourArgs = new GenericTypeNode(new IdentifierTypeNode('\\Generator'), [
                new IdentifierTypeNode('string'),
                new IdentifierTypeNode('int'),
                new IdentifierTypeNode('positive-int'),
                new IdentifierTypeNode('bool'),
            ]);
            $contract4 = GeneratorEvaluator::extractContract($fourArgs);
            expect($contract4)->not()->toBeNull()
                ->and((string) $contract4['key'])->toBe('string')
                ->and((string) $contract4['value'])->toBe('int')
                ->and((string) $contract4['send'])->toBe('positive-int')
                ->and((string) $contract4['return'])->toBe('bool')
            ;
        });

        test('extracts contract from ArrayTypeNode (T[]) and bare Generator/iterable identifiers', function () {
            $arrayType = new ArrayTypeNode(new IdentifierTypeNode('non-empty-string'));
            $contractArr = GeneratorEvaluator::extractContract($arrayType);
            expect($contractArr)->not()->toBeNull()
                ->and($contractArr['key'])->toBeNull()
                ->and((string) $contractArr['value'])->toBe('non-empty-string')
            ;

            $bareGen = new IdentifierTypeNode('Generator');
            $contractBare = GeneratorEvaluator::extractContract($bareGen);
            expect($contractBare)->not()->toBeNull()
                ->and($contractBare['key'])->toBeNull()
                ->and($contractBare['value'])->toBeNull()
            ;
        });

        test('returns null for unknown type aliases or non-generator generic classes', function () {
            expect(GeneratorEvaluator::extractContract(new IdentifierTypeNode('CustomStreamAlias')))->toBeNull();
            expect(GeneratorEvaluator::extractContract(new GenericTypeNode(new IdentifierTypeNode('Collection'), [new IdentifierTypeNode('int')])))->toBeNull();
        });
    });

    describe('canEliminateYield()', function () {
        test('eliminates yield when literal key and value satisfy contract', function () {
            $yield = new Yield_(new LNumber(42), new String_('key_a'));

            expect(GeneratorEvaluator::canEliminateYield(
                $yield,
                new IdentifierTypeNode('non-empty-string'),
                new IdentifierTypeNode('positive-int')
            ))->toBeTrue();
        });

        test('eliminates yield when string concatenation and interpolation satisfy non-empty-string and array-key', function () {
            $concatKey = new Concat(new String_('user_'), new Variable('id'));
            $encapsedVal = new Encapsed([
                new EncapsedStringPart('item_'),
                new Variable('name'),
            ]);
            $yield = new Yield_($encapsedVal, $concatKey);

            expect(GeneratorEvaluator::canEliminateYield(
                $yield,
                new IdentifierTypeNode('non-empty-string'),
                new IdentifierTypeNode('non-empty-string')
            ))->toBeTrue();

            expect(GeneratorEvaluator::canEliminateYield(
                $yield,
                new IdentifierTypeNode('array-key'),
                new IdentifierTypeNode('non-empty-string')
            ))->toBeTrue();
        });

        test('handles omitted key (implicit 0..n int key) accurately against int vs positive-int', function () {
            $yieldNoKey = new Yield_(new String_('hello'));

            expect(GeneratorEvaluator::canEliminateYield(
                $yieldNoKey,
                new IdentifierTypeNode('int'),
                new IdentifierTypeNode('non-empty-string')
            ))->toBeTrue();

            expect(GeneratorEvaluator::canEliminateYield(
                $yieldNoKey,
                new IdentifierTypeNode('non-negative-int'),
                new IdentifierTypeNode('non-empty-string')
            ))->toBeTrue();

            // Implicit key starts at 0, which violates positive-int and string
            expect(GeneratorEvaluator::canEliminateYield(
                $yieldNoKey,
                new IdentifierTypeNode('positive-int'),
                new IdentifierTypeNode('non-empty-string')
            ))->toBeFalse();

            expect(GeneratorEvaluator::canEliminateYield(
                $yieldNoKey,
                new IdentifierTypeNode('string'),
                new IdentifierTypeNode('non-empty-string')
            ))->toBeFalse();
        });

        test('retains checkYield when value or key is unproven or violates contract', function () {
            $badVal = new Yield_(new LNumber(-5), new String_('a'));
            expect(GeneratorEvaluator::canEliminateYield(
                $badVal,
                new IdentifierTypeNode('string'),
                new IdentifierTypeNode('positive-int')
            ))->toBeFalse();

            $dynamicVal = new Yield_(new Variable('unproven'), new String_('a'));
            expect(GeneratorEvaluator::canEliminateYield(
                $dynamicVal,
                new IdentifierTypeNode('string'),
                new IdentifierTypeNode('positive-int')
            ))->toBeFalse();
        });
    });

    describe('canEliminateSend()', function () {
        test('eliminates checkSend when TSend is null or mixed, and retains when constrained', function () {
            expect(GeneratorEvaluator::canEliminateSend(null))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateSend(new IdentifierTypeNode('mixed')))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateSend(new IdentifierTypeNode('positive-int')))->toBeFalse()
            ;
        });
    });

    describe('canEliminateYieldFrom()', function () {
        test('eliminates wrapIterable on yield from array literal with valid elements and concat strings', function () {
            $yieldFrom = new YieldFrom(new Array_([
                new ArrayItem(new Concat(new String_('val_'), new Variable('x')), new Concat(new String_('key_'), new Variable('k'))),
            ]));

            expect(GeneratorEvaluator::canEliminateYieldFrom(
                $yieldFrom,
                new IdentifierTypeNode('non-empty-string'),
                new IdentifierTypeNode('non-empty-string')
            ))->toBeTrue();
        });

        test('retains wrapIterable when yield from expression is dynamic or contains invalid item', function () {
            $dynamicYieldFrom = new YieldFrom(new Variable('items'));
            expect(GeneratorEvaluator::canEliminateYieldFrom(
                $dynamicYieldFrom,
                null,
                new IdentifierTypeNode('positive-int')
            ))->toBeFalse();

            $badArrayYieldFrom = new YieldFrom(new Array_([
                new ArrayItem(new LNumber(10)),
                new ArrayItem(new LNumber(-5)),
            ]));
            expect(GeneratorEvaluator::canEliminateYieldFrom(
                $badArrayYieldFrom,
                null,
                new IdentifierTypeNode('positive-int')
            ))->toBeFalse();
        });
    });

    describe('canEliminateGeneratorReturn()', function () {
        test('eliminates generator return when TReturn is null, mixed, or satisfied by expression', function () {
            expect(GeneratorEvaluator::canEliminateGeneratorReturn(null, null))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateGeneratorReturn(null, new IdentifierTypeNode('mixed')))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateGeneratorReturn(null, new IdentifierTypeNode('void')))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateGeneratorReturn(new ConstFetch(new Name('true')), new IdentifierTypeNode('bool')))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateGeneratorReturn(new Concat(new String_('ok_'), new Variable('id')), new IdentifierTypeNode('non-empty-string')))->toBeTrue()
            ;

            expect(GeneratorEvaluator::canEliminateGeneratorReturn(null, new IdentifierTypeNode('bool')))->toBeFalse()
                ->and(GeneratorEvaluator::canEliminateGeneratorReturn(new String_('not_void'), new IdentifierTypeNode('void')))->toBeFalse()
            ;
        });
    });

    describe('EliminationSafetyPolicy::isGeneratorEliminationSafe()', function () {
        test('authorizes standalone functions, private methods, and non-inherited classes, and rejects inherited public methods', function () {
            $genType = new GenericTypeNode(new IdentifierTypeNode('Generator'), [new IdentifierTypeNode('int')]);

            $fn = new Function_('myGen');
            expect(EliminationSafetyPolicy::isGeneratorEliminationSafe($fn, null, $genType))->toBeTrue();

            $privateMethod = new ClassMethod('privGen', ['flags' => Class_::MODIFIER_PRIVATE]);
            expect(EliminationSafetyPolicy::isGeneratorEliminationSafe($privateMethod, ['hasRealInheritance' => true], $genType))->toBeTrue();

            $standaloneMethod = new ClassMethod('pubGen', ['flags' => Class_::MODIFIER_PUBLIC]);
            expect(EliminationSafetyPolicy::isGeneratorEliminationSafe($standaloneMethod, ['hasRealInheritance' => false], $genType))->toBeTrue();

            $inheritedMethod = new ClassMethod('pubGen', ['flags' => Class_::MODIFIER_PUBLIC]);
            expect(EliminationSafetyPolicy::isGeneratorEliminationSafe($inheritedMethod, ['hasRealInheritance' => true], $genType))->toBeFalse();
        });
    });

    describe('canEliminateSend()', function () {
        test('eliminates checkSend when TSend is null, mixed, or void, and retains when constrained', function () {
            expect(GeneratorEvaluator::canEliminateSend(null))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateSend(new IdentifierTypeNode('mixed')))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateSend(new IdentifierTypeNode('void')))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateSend(new IdentifierTypeNode('null')))->toBeTrue()
                ->and(GeneratorEvaluator::canEliminateSend(new IdentifierTypeNode('positive-int')))->toBeFalse()
            ;
        });
    });
});
