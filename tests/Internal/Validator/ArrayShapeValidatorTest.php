<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\ArrayShapeValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

describe('ArrayShapeValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new ArrayShapeValidator();
    });

    test('rejects non-array inputs', function () {
        $shape = ArrayShapeNode::createSealed([]);
        $err = $this->validator->validate('not_array', $shape, 'payload', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('must be of type array')
        ;
    });

    test('rejects associative array when shape kind is KIND_LIST', function () {
        $shape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(null, false, new IdentifierTypeNode('int')),
        ], ArrayShapeNode::KIND_LIST);

        $assoc = ['non_sequential' => 10];
        $err = $this->validator->validate($assoc, $shape, 'tuple', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('must be a list')
        ;
    });

    test('validates required and optional shape keys', function () {
        $shape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
            new ArrayShapeItemNode(new ConstExprStringNode('name', ConstExprStringNode::SINGLE_QUOTED), true, new IdentifierTypeNode('string')),
        ]);

        expect($this->validator->validate(['id' => 10], $shape, 'user', $this->registry))->toBeNull();
        expect($this->validator->validate(['id' => 10, 'name' => 'Alice'], $shape, 'user', $this->registry))->toBeNull();

        $err = $this->validator->validate(['name' => 'Alice'], $shape, 'user', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("is missing required key 'id'")
        ;

        $errVal = $this->validator->validate(['id' => -5], $shape, 'user', $this->registry);
        expect($errVal)->toBeInstanceOf(ErrorMessage::class)
            ->and($errVal->getMessage())->toContain("user['id']")
        ;
    });

    test('rejects unexpected keys in sealed shapes', function () {
        $shape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('int')),
        ]);

        $err = $this->validator->validate(['id' => 1, 'extra' => 'forbidden'], $shape, 'data', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("contains unsealed unexpected key 'extra'")
        ;
    });

    test('validates unsealed shapes with extra key and value constraints', function () {
        $unsealed = new ArrayShapeUnsealedTypeNode(
            new IdentifierTypeNode('string'),
            new IdentifierTypeNode('non-empty-string')
        );

        $shape = ArrayShapeNode::createUnsealed([
            new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('int')),
        ], $unsealed);

        expect($this->validator->validate(['id' => 1, 'note' => 'extra info'], $shape, 'payload', $this->registry))->toBeNull();

        $errVal = $this->validator->validate(['id' => 1, 'count' => 12345], $shape, 'payload', $this->registry);
        expect($errVal)->toBeInstanceOf(ErrorMessage::class)
            ->and($errVal->getMessage())->toContain("payload['count'] must be of type string")
        ;

        $errKey = $this->validator->validate(['id' => 1, '' => 'empty_key_val'], $shape, 'payload', $this->registry);
        expect($errKey)->toBeInstanceOf(ErrorMessage::class)
            ->and($errKey->getMessage())->toContain("extra key ''")
        ;
    });

    test('supports integer and auto-indexed keys in tuple shapes', function () {
        $shape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprIntegerNode('0'), false, new IdentifierTypeNode('positive-int')),
            new ArrayShapeItemNode(null, false, new IdentifierTypeNode('string')),
        ]);

        expect($this->validator->validate([10, 'hello'], $shape, 'tuple', $this->registry))->toBeNull();

        $err = $this->validator->validate([-5, 'hello'], $shape, 'tuple', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("tuple['0']")
        ;
    });

    test('supports ConstFetchNode keys in array shape items', function () {
        $shape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(
                new PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode('self', 'KEY_NAME'),
                false,
                new IdentifierTypeNode('string')
            ),
        ]);

        expect($this->validator->validate(['self::KEY_NAME' => 'value'], $shape, 'payload', $this->registry))->toBeNull();
    });
});
