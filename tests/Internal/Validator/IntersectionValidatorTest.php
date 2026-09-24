<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\IntersectionValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Types\ArrayAccessOnly;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;

class IntersectionDummyUser
{
    public int $id = 10;

    public string $name = 'Alice';
}

describe('IntersectionValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new IntersectionValidator();
    });

    test('validates objects implementing all intersected interfaces', function () {
        $intersection = new IntersectionTypeNode([
            new IdentifierTypeNode('Countable'),
            new IdentifierTypeNode('ArrayAccess'),
        ]);

        $valid = new CountableArrayAccess();
        expect($this->validator->validate($valid, $intersection, 'collection', $this->registry))->toBeNull();

        $onlyCountable = new CountableOnly();
        $err = $this->validator->validate($onlyCountable, $intersection, 'collection', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('must be of type (Countable & ArrayAccess)')
        ;

        $onlyArrayAccess = new ArrayAccessOnly();
        $errAccess = $this->validator->validate($onlyArrayAccess, $intersection, 'collection', $this->registry);
        expect($errAccess)->toBeInstanceOf(ErrorMessage::class);
    });

    test('merges two sealed array shapes in an intersection', function () {
        $shapeA = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
        ]);
        $shapeB = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('name', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('non-empty-string')),
        ]);
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB]);

        $valid = ['id' => 10, 'name' => 'Alice'];
        expect($this->validator->validate($valid, $intersection, 'data', $this->registry))->toBeNull();

        $missingKey = ['id' => 10];
        $errMissing = $this->validator->validate($missingKey, $intersection, 'data', $this->registry);
        expect($errMissing)->toBeInstanceOf(ErrorMessage::class)
            ->and($errMissing->getMessage())->toContain("missing required key 'name'")
        ;

        $unexpectedKey = ['id' => 10, 'name' => 'Alice', 'extra' => 'forbidden'];
        $errExtra = $this->validator->validate($unexpectedKey, $intersection, 'data', $this->registry);
        expect($errExtra)->toBeInstanceOf(ErrorMessage::class)
            ->and($errExtra->getMessage())->toContain("contains unsealed unexpected key 'extra'")
        ;
    });

    test('merges unsealed array shapes in an intersection', function () {
        $unsealed = new ArrayShapeUnsealedTypeNode(new IdentifierTypeNode('string'), null);
        $shapeA = ArrayShapeNode::createUnsealed([
            new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('int')),
        ], $unsealed);
        $shapeB = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('tag', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('string')),
        ]);
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB]);

        $validWithExtra = ['id' => 10, 'tag' => 'main', 'extra' => 'allowed_extra_string'];
        expect($this->validator->validate($validWithExtra, $intersection, 'data', $this->registry))->toBeNull();
    });

    test('merges list-kind and keyless tuple array shapes', function () {
        $tupleA = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprIntegerNode('0'), false, new IdentifierTypeNode('int')),
        ], ArrayShapeNode::KIND_LIST);
        $tupleB = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(null, false, new IdentifierTypeNode('positive-int')),
        ], ArrayShapeNode::KIND_LIST);
        $intersection = new IntersectionTypeNode([$tupleA, $tupleB]);

        expect($this->validator->validate([10, 20], $intersection, 'tuple', $this->registry))->toBeNull();

        $err = $this->validator->validate([10, -5], $intersection, 'tuple', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("tuple['1']")
        ;
    });

    test('merges overlapping keys with required and optional flags', function () {
        $shapeA = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new IdentifierTypeNode('id'), true, new IdentifierTypeNode('int')),
        ]);
        $shapeB = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
        ]);
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB]);

        expect($this->validator->validate(['id' => 10], $intersection, 'val', $this->registry))->toBeNull();

        $err = $this->validator->validate(['id' => -10], $intersection, 'val', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("val['id']")
        ;
    });

    test('merges two object shapes in an intersection', function () {
        $shapeA = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
        ]);
        $shapeB = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('name'), false, new IdentifierTypeNode('non-empty-string')),
        ]);
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB]);

        $validObj = new IntersectionDummyUser();
        expect($this->validator->validate($validObj, $intersection, 'user', $this->registry))->toBeNull();

        $std = new stdClass();
        $std->id = 10;
        $std->name = 'Alice';
        expect($this->validator->validate($std, $intersection, 'user', $this->registry))->toBeNull();

        $badStd = new stdClass();
        $badStd->id = 10;
        $badStd->name = '';
        $err = $this->validator->validate($badStd, $intersection, 'user', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('user->name')
        ;
    });

    test('extracts keys with ConstFetchNode in array shape merging', function () {
        $shapeA = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstFetchNode('self', 'KEY'), false, new IdentifierTypeNode('int')),
        ]);
        $shapeB = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('other', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('string')),
        ]);
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB]);

        $valid = ['self::KEY' => 10, 'other' => 'text'];
        expect($this->validator->validate($valid, $intersection, 'data', $this->registry))->toBeNull();
    });

    test('leaves single shape intersections unmerged when count is less than two', function () {
        $shape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('int')),
        ]);
        $intersection = new IntersectionTypeNode([$shape]);

        expect($this->validator->validate(['id' => 1], $intersection, 'item', $this->registry))->toBeNull();
    });

    test('merges array shapes while preserving non-shape types in intersection', function () {
        $shapeA = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('int')),
        ]);
        $shapeB = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new IdentifierTypeNode('name'), false, new IdentifierTypeNode('string')),
        ]);
        $nonShapeType = new IdentifierTypeNode('array');
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB, $nonShapeType]);

        expect($this->validator->validate(['id' => 10, 'name' => 'Alice'], $intersection, 'data', $this->registry))->toBeNull();
    });

    test('merges object shapes while preserving non-shape types in intersection', function () {
        $shapeA = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('int')),
        ]);
        $shapeB = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('name'), false, new IdentifierTypeNode('string')),
        ]);
        $nonShapeType = new IdentifierTypeNode(stdClass::class);
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB, $nonShapeType]);

        $std = new stdClass();
        $std->id = 10;
        $std->name = 'Alice';

        expect($this->validator->validate($std, $intersection, 'data', $this->registry))->toBeNull();
    });

    test('merges object shapes with overlapping properties into an intersection', function () {
        $shapeA = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('score'), true, new IdentifierTypeNode('int')),
        ]);
        $shapeB = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('score'), false, new IdentifierTypeNode('positive-int')),
        ]);
        $intersection = new IntersectionTypeNode([$shapeA, $shapeB]);

        $std = new stdClass();
        $std->score = 50;
        expect($this->validator->validate($std, $intersection, 'data', $this->registry))->toBeNull();

        $badStd = new stdClass();
        $badStd->score = -10;
        $err = $this->validator->validate($badStd, $intersection, 'data', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('data->score')
        ;
    });
});
