<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Docblock\DocblockNormalizer;
use TypePHP\Tests\Fixtures\Types\StatusEnum;

class ConditionalConstRole
{
    public const ADMIN = 1;
    public const USER = 2;
    public const KEY_LABEL = 'label';
}

class ConditionalSelfConstService
{
    public const CODE_OK = 200;
    public const CODE_FAIL = 500;

    /**
     * @param bool $ok
     *
     * @return ($ok is true ? self::CODE_OK : self::CODE_FAIL)
     */
    public function getStatusCode(bool $ok, mixed $val): mixed
    {
        return $val;
    }
}

/**
 * @param bool $isAdmin
 *
 * @return ($isAdmin is true ? ConditionalConstRole::ADMIN : ConditionalConstRole::USER)
 */
function resolveConditionalRoleConstant(bool $isAdmin, mixed $value): mixed
{
    return $value;
}

/**
 * @param bool $isActive
 *
 * @return ($isActive is true ? StatusEnum::Active : StatusEnum::Inactive)
 */
function resolveConditionalEnumCase(bool $isActive, StatusEnum $status): StatusEnum
{
    return $status;
}

/**
 * Mixes a class-constant array shape key AND a conditional true-branch class constant in the same shape.
 *
 * @param bool $isAdmin
 *
 * @return array{role: ($isAdmin is true ? ConditionalConstRole::ADMIN : ConditionalConstRole::USER), ConditionalConstRole::KEY_LABEL: non-empty-string}
 */
function resolveShapeWithKeyAndConditionalConst(bool $isAdmin, mixed $roleVal, string $label): array
{
    return [
        'role' => $roleVal,
        ConditionalConstRole::KEY_LABEL => $label,
    ];
}

describe('DocblockNormalizer Class Constants in Conditional Types (Red Phase)', function () {
    describe('1. DocblockNormalizer Unit Assertions', function () {
        test('does not quote class constant in conditional true branch', function () {
            $doc = '/** @return ($isAdmin is true ? Role::ADMIN : Role::USER) */';

            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('does not quote self:: constant in template conditional true branch', function () {
            $doc = '/** @return (T is int ? self::INT_CODE : self::STR_CODE) */';

            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('does not quote class constant in conditional nested inside an array shape value', function () {
            $doc = '/** @return array{role: ($isAdmin is true ? Role::ADMIN : Role::USER)} */';

            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('does not quote conditional class constant when another tag in the same docblock contains braces', function () {
            $doc = "/**\n * @param array{id: int} \$cfg\n * @return (\$isAdmin is true ? Role::ADMIN : Role::USER)\n */";

            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('still quotes genuine class constant keys inside single-line and multi-line array shapes', function () {
            $single = '/** @param array{self::KEY_ID: int, App\Constants::ROLE?: string} $payload */';
            expect(DocblockNormalizer::normalize($single))
                ->toBe('/** @param array{"self::KEY_ID": int, "App\Constants::ROLE"?: string} $payload */')
            ;

            $multi = "/**\n * @param array{\n *     self::KEY_ID: int,\n *     App\\Constants::ROLE: string\n * } \$payload\n */";
            $expectedMulti = "/**\n * @param array{\n *     \"self::KEY_ID\": int,\n *     \"App\\Constants::ROLE\": string\n * } \$payload\n */";
            expect(DocblockNormalizer::normalize($multi))->toBe($expectedMulti);
        });
    });

    describe('2. End-to-End Runtime Conditional Evaluation with Class Constants & Enums', function () {
        test('accepts actual constant value and rejects stringified constant name in parameter conditional true branch', function () {
            expect(resolveConditionalRoleConstant(true, ConditionalConstRole::ADMIN))->toBe(1);
            expect(resolveConditionalRoleConstant(false, ConditionalConstRole::USER))->toBe(2);

            expect(fn () => resolveConditionalRoleConstant(true, 'ConditionalConstRole::ADMIN'))
                ->toThrow(TypeError::class)
            ;
            expect(fn () => resolveConditionalRoleConstant(true, ConditionalConstRole::USER))
                ->toThrow(TypeError::class)
            ;
        });

        test('resolves self::CONST in class method conditional true branch', function () {
            $service = new ConditionalSelfConstService();

            expect($service->getStatusCode(true, 200))->toBe(200);
            expect($service->getStatusCode(false, 500))->toBe(500);

            expect(fn () => $service->getStatusCode(true, 500))
                ->toThrow(TypeError::class)
            ;
        });

        test('accepts Enum::Case in conditional true branch', function () {
            expect(resolveConditionalEnumCase(true, StatusEnum::Active))->toBe(StatusEnum::Active);
            expect(resolveConditionalEnumCase(false, StatusEnum::Inactive))->toBe(StatusEnum::Inactive);

            expect(fn () => resolveConditionalEnumCase(true, StatusEnum::Inactive))
                ->toThrow(TypeError::class)
            ;
        });

        test('quotes shape constant key while leaving conditional constant value intact inside the same shape', function () {
            $valid = resolveShapeWithKeyAndConditionalConst(true, ConditionalConstRole::ADMIN, 'Admin User');
            expect($valid)->toBe([
                'role' => 1,
                'label' => 'Admin User',
            ]);

            expect(fn () => resolveShapeWithKeyAndConditionalConst(true, ConditionalConstRole::USER, 'Admin User'))
                ->toThrow(TypeError::class, "['role']")
            ;
        });
    });
});
