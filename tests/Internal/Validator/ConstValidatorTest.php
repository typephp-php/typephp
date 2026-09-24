<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\ConstValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Types\WildcardConstantFixture;

class SampleDefinedClassConst
{
    public const VALID_STATUS = 'ready';
}

describe('ConstValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new ConstValidator();
    });

    test('validates string literals', function () {
        $node = new ConstTypeNode(new ConstExprStringNode('active', ConstExprStringNode::SINGLE_QUOTED));

        expect($this->validator->validate('active', $node, 'arg', $this->registry))->toBeNull();

        $err = $this->validator->validate('pending', $node, 'arg', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("must be literal 'active'")
        ;
    });

    test('validates boolean true and false literals', function () {
        $trueNode = new ConstTypeNode(new ConstExprTrueNode());
        expect($this->validator->validate(true, $trueNode, 'arg', $this->registry))->toBeNull();
        expect($this->validator->validate(false, $trueNode, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $falseNode = new ConstTypeNode(new ConstExprFalseNode());
        expect($this->validator->validate(false, $falseNode, 'arg', $this->registry))->toBeNull();
        expect($this->validator->validate(true, $falseNode, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates null literals', function () {
        $nullNode = new ConstTypeNode(new ConstExprNullNode());
        expect($this->validator->validate(null, $nullNode, 'arg', $this->registry))->toBeNull();
        expect($this->validator->validate('not_null', $nullNode, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates integer literals', function () {
        $intNode = new ConstTypeNode(new ConstExprIntegerNode('42'));
        expect($this->validator->validate(42, $intNode, 'arg', $this->registry))->toBeNull();
        expect($this->validator->validate(100, $intNode, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates float literals with IEEE 754 precision and int coercion', function () {
        $floatNode = new ConstTypeNode(new ConstExprFloatNode('12.34'));
        expect($this->validator->validate(12.34, $floatNode, 'arg', $this->registry))->toBeNull();
        expect($this->validator->validate(12.35, $floatNode, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $precisionNode = new ConstTypeNode(new ConstExprFloatNode('0.3'));
        expect($this->validator->validate(0.1 + 0.2, $precisionNode, 'arg', $this->registry))->toBeNull();

        $coercionNode = new ConstTypeNode(new ConstExprFloatNode('10.0'));
        expect($this->validator->validate(10, $coercionNode, 'arg', $this->registry))->toBeNull()
            ->and($this->validator->validate(10.0, $coercionNode, 'arg', $this->registry))->toBeNull()
            ->and($this->validator->validate('not_numeric', $coercionNode, 'arg', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('validates defined class constants and handles undefined constant fallback', function () {
        $definedNode = new ConstTypeNode(new ConstFetchNode(SampleDefinedClassConst::class, 'VALID_STATUS'));
        expect($this->validator->validate('ready', $definedNode, 'status', $this->registry))->toBeNull();
        expect($this->validator->validate('unknown', $definedNode, 'status', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $undefinedNode = new ConstTypeNode(new ConstFetchNode('NonExistentClass', 'UNDEFINED_CONST'));
        expect($this->validator->validate('anything', $undefinedNode, 'status', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates global constants without class names', function () {
        $globalConst = new ConstTypeNode(new ConstFetchNode('', 'PHP_VERSION_ID'));

        expect($this->validator->validate(PHP_VERSION_ID, $globalConst, 'version', $this->registry))->toBeNull();
        expect($this->validator->validate(0, $globalConst, 'version', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('handles custom or unsupported constant expression fallbacks', function () {
        $arrayConstExpr = new PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprArrayNode([]);

        $node = new ConstTypeNode($arrayConstExpr);

        expect($this->validator->validate('[]', $node, 'val', $this->registry))->toBeNull();
        expect($this->validator->validate('mismatch', $node, 'val', $this->registry))->toBeInstanceOf(ErrorMessage::class);
    });

    test('validates wildcard constant patterns', function () {
        $wildcardNode = new ConstTypeNode(new ConstFetchNode(WildcardConstantFixture::class, 'VERSION_SELECTION_*'));

        expect($this->validator->validate('all', $wildcardNode, 'mode', $this->registry))->toBeNull();
        expect($this->validator->validate('blue-green', $wildcardNode, 'mode', $this->registry))->toBeNull();

        $err = $this->validator->validate('invalid_mode', $wildcardNode, 'mode', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('must be a valid constant matching')
        ;

        $unqualifiedWildcard = new ConstTypeNode(new ConstFetchNode('', 'PREFIX_*'));
        $errUnqualified = $this->validator->validate('any', $unqualifiedWildcard, 'mode', $this->registry);
        expect($errUnqualified)->toBeInstanceOf(ErrorMessage::class);
    });
});
