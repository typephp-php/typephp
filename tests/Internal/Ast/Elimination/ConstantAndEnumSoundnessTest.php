<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Tests\Fixtures\Enums\Suit;
use TypePHP\Tests\Fixtures\Types\StatusEnum;

class SoundnessOrderConst
{
    public const STATUS_PAID = 1;
}

class SoundnessUserConst
{
    public const STATUS_PAID = 'wrong_user_status';
}

class SoundnessFlagsA
{
    public const FLAG_READ = 1;
    public const FLAG_READ_EXTRA = 8;
}

class SoundnessFlagsB
{
    public const FLAG_WRITE = 999;
}

enum SoundnessEnumWithConst: string
{
    public const NON_CASE_LABEL = 'not_a_case';

    case Active = 'active';
}

describe('ConstantAndEnumEvaluator Soundness (Red Phase)', function () {
    describe('1. Cross-Class Constant & Wildcard Isolation', function () {
        test('does not eliminate ClassConstFetch when expression class differs from ConstTypeNode class', function () {
            $fetch = new ClassConstFetch(new Name('User'), new Identifier('STATUS_PAID'));
            $exact = new ConstTypeNode(new ConstFetchNode('Order', 'STATUS_PAID'));
            $wildcard = new ConstTypeNode(new ConstFetchNode('Order', 'STATUS_*'));

            expect(StaticTypeEvaluator::satisfies($fetch, $exact))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($fetch, $wildcard))->toBeFalse()
            ;
        });

        test('does not eliminate ClassConstFetch against global constant without class name', function () {
            $fetch = new ClassConstFetch(new Name('Order'), new Identifier('PHP_VERSION_ID'));
            $globalConst = new ConstTypeNode(new ConstFetchNode('', 'PHP_VERSION_ID'));

            expect(StaticTypeEvaluator::satisfies($fetch, $globalConst))->toBeFalse();
        });

        test('retains checkReturn at AST transform time and throws TypeError at runtime on cross-class constant return', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

/**
 * @return SoundnessOrderConst::STATUS_*
 */
function returnWrongClassConst(): mixed
{
    return SoundnessUserConst::STATUS_PAID;
}

return returnWrongClassConst();
PHP;

            $transformed = StreamWrapper::transformSource($source, __FILE__);
            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');

            $tempDir = sys_get_temp_dir() . '/typephp_const_soundness_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/cross_class_const.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, 'must be a valid constant matching')
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });

    describe('2. int-mask-of Class Isolation & Non-Wildcard Matching', function () {
        test('does not eliminate int-mask-of when bitwise OR mixes a constant from a different class', function () {
            $flagA = new ClassConstFetch(new Name('FlagsA'), new Identifier('FLAG_READ'));
            $flagB = new ClassConstFetch(new Name('FlagsB'), new Identifier('FLAG_WRITE'));
            $orExpr = new BitwiseOr($flagA, $flagB);

            $maskOfA = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
                new ConstTypeNode(new ConstFetchNode('FlagsA', 'FLAG_*')),
            ]);

            expect(StaticTypeEvaluator::satisfies($flagB, $maskOfA))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($orExpr, $maskOfA))->toBeFalse()
            ;
        });

        test('does not match prefix substring when int-mask-of pattern has no asterisk wildcard', function () {
            $fetch = new ClassConstFetch(new Name('self'), new Identifier('FLAG_READ_EXTRA'));
            $nonWildcardMask = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
                new ConstTypeNode(new ConstFetchNode('self', 'FLAG_READ')),
            ]);

            expect(StaticTypeEvaluator::satisfies($fetch, $nonWildcardMask))->toBeFalse();
        });
    });

    describe('3. ::class Pseudo-Constant vs Class::* Wildcards & Object Types', function () {
        test('does not satisfy Class::* wildcard when fetching ::class pseudo-constant', function () {
            $classFetch = new ClassConstFetch(new Name(StatusEnum::class), new Identifier('class'));
            $wildcard = new ConstTypeNode(new ConstFetchNode(StatusEnum::class, '*'));

            expect(StaticTypeEvaluator::satisfies($classFetch, $wildcard, __FILE__))->toBeFalse();
        });

        test('retains checkReturn and throws TypeError when returning Enum::class for @return Enum::*', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use TypePHP\Tests\Fixtures\Types\StatusEnum;

/**
 * @return StatusEnum::*
 */
function returnEnumClassString(): mixed
{
    return StatusEnum::class;
}

return returnEnumClassString();
PHP;

            $transformed = StreamWrapper::transformSource($source, __FILE__);
            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');

            $tempDir = sys_get_temp_dir() . '/typephp_enum_class_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/enum_class_wildcard.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, 'must be a valid constant matching')
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });

    describe('4. ClassConstFetch vs IdentifierTypeNode (Non-Enum Constants, ::class, Enum Scalar Consts)', function () {
        test('does not eliminate Class::CONST or Class::class against @return Class', function () {
            $constFetch = new ClassConstFetch(new Name(SoundnessOrderConst::class), new Identifier('STATUS_PAID'));
            $classFetch = new ClassConstFetch(new Name(SoundnessOrderConst::class), new Identifier('class'));
            $targetType = new IdentifierTypeNode(SoundnessOrderConst::class);

            expect(StaticTypeEvaluator::satisfies($constFetch, $targetType, __FILE__))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($classFetch, $targetType, __FILE__))->toBeFalse()
            ;
        });

        test('does not eliminate regular scalar const declared inside a BackedEnum against @return Enum', function () {
            $scalarConstOnEnum = new ClassConstFetch(new Name(SoundnessEnumWithConst::class), new Identifier('NON_CASE_LABEL'));
            $targetType = new IdentifierTypeNode(SoundnessEnumWithConst::class);

            expect(StaticTypeEvaluator::satisfies($scalarConstOnEnum, $targetType, __FILE__))->toBeFalse();
        });

        test('still eliminates genuine loaded Enum case against @return Enum', function () {
            $enumCaseFetch = new ClassConstFetch(new Name(StatusEnum::class), new Identifier('Active'));
            $targetType = new IdentifierTypeNode(StatusEnum::class);

            expect(StaticTypeEvaluator::satisfies($enumCaseFetch, $targetType, __FILE__))->toBeTrue();
        });

        test('throws TypeError at runtime when returning regular class constant for @return ClassName', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

/**
 * @return SoundnessOrderConst
 */
function returnOrderScalarConstAsOrder(): mixed
{
    return SoundnessOrderConst::STATUS_PAID;
}

return returnOrderScalarConstAsOrder();
PHP;

            $transformed = StreamWrapper::transformSource($source, __FILE__);
            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');

            $tempDir = sys_get_temp_dir() . '/typephp_const_as_obj_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/const_as_obj.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, SoundnessOrderConst::class)
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });

    describe('5. value-of<Enum> Backing Property Soundness', function () {
        test('does not eliminate UnitEnum::Case->value or non-enum Class::CONST->value against value-of', function () {
            $unitEnumValue = new PropertyFetch(
                new ClassConstFetch(new Name(Suit::class), new Identifier('Hearts')),
                new Identifier('value')
            );
            $unitTarget = new GenericTypeNode(new IdentifierTypeNode('value-of'), [new IdentifierTypeNode(Suit::class)]);

            $classConstValue = new PropertyFetch(
                new ClassConstFetch(new Name(SoundnessOrderConst::class), new Identifier('STATUS_PAID')),
                new Identifier('value')
            );
            $classTarget = new GenericTypeNode(new IdentifierTypeNode('value-of'), [new IdentifierTypeNode(SoundnessOrderConst::class)]);

            expect(StaticTypeEvaluator::satisfies($unitEnumValue, $unitTarget, __FILE__))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($classConstValue, $classTarget, __FILE__))->toBeFalse()
            ;
        });

        test('still eliminates genuine BackedEnum::Case->value against value-of<BackedEnum>', function () {
            $backedEnumValue = new PropertyFetch(
                new ClassConstFetch(new Name(StatusEnum::class), new Identifier('Active')),
                new Identifier('value')
            );
            $target = new GenericTypeNode(new IdentifierTypeNode('value-of'), [new IdentifierTypeNode(StatusEnum::class)]);

            expect(StaticTypeEvaluator::satisfies($backedEnumValue, $target, __FILE__))->toBeTrue();
        });
    });
});
