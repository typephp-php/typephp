<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Validator\UnionValidator;

class StringableDiscriminator implements Stringable
{
    public function __toString(): string
    {
        return 'click';
    }
}

describe('UnionValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new UnionValidator();
    });

    test('validates fast-path primitive and object union members', function () {
        $union = new UnionTypeNode([
            new IdentifierTypeNode('int'),
            new IdentifierTypeNode('string'),
            new IdentifierTypeNode('bool'),
            new IdentifierTypeNode('null'),
            new IdentifierTypeNode('object'),
            new IdentifierTypeNode('class-string'),
        ]);

        expect($this->validator->validate(10, $union, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate('hello', $union, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(true, $union, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(null, $union, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(new stdClass(), $union, 'val', $this->registry))->toBeNull()
            ->and($this->validator->validate(stdClass::class, $union, 'val', $this->registry))->toBeNull()
        ;
    });

    test('bubbles deep errors from nested array shapes', function () {
        $shapeA = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
        ]);
        $shapeB = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('code', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('string')),
        ]);
        $union = new UnionTypeNode([$shapeA, $shapeB]);

        $badPayload = ['id' => -10];
        $err = $this->validator->validate($badPayload, $union, 'payload', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("payload['id']")
        ;
    });

    test('selects matching discriminator branch in array shapes', function () {
        $clickShape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('type', ConstExprStringNode::SINGLE_QUOTED), false, new ConstTypeNode(new ConstExprStringNode('click', ConstExprStringNode::SINGLE_QUOTED))),
            new ArrayShapeItemNode(new ConstExprStringNode('x', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
        ]);
        $scrollShape = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprStringNode('type', ConstExprStringNode::SINGLE_QUOTED), false, new ConstTypeNode(new ConstExprStringNode('scroll', ConstExprStringNode::SINGLE_QUOTED))),
            new ArrayShapeItemNode(new ConstExprStringNode('offset', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
        ]);
        $union = new UnionTypeNode([$clickShape, $scrollShape]);

        expect($this->validator->validate(['type' => 'click', 'x' => 10], $union, 'event', $this->registry))->toBeNull();
        expect($this->validator->validate(['type' => 'click', 'x' => new StringableDiscriminator()], $union, 'event', $this->registry))->toBeInstanceOf(ErrorMessage::class);

        $badClick = ['type' => 'click', 'x' => -5];
        $errClick = $this->validator->validate($badClick, $union, 'event', $this->registry);
        expect($errClick)->toBeInstanceOf(ErrorMessage::class)
            ->and($errClick->getMessage())->toContain("event['x']")
        ;

        $unmatched = ['type' => 'unknown', 'extra' => 123];
        $errUnmatched = $this->validator->validate($unmatched, $union, 'event', $this->registry);
        expect($errUnmatched)->toBeInstanceOf(ErrorMessage::class)
            ->and($errUnmatched->getMessage())->toContain('must be of type')
        ;
    });

    test('selects matching discriminator branch in object shapes', function () {
        $userShape = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('kind'), false, new ConstTypeNode(new ConstExprStringNode('user', ConstExprStringNode::SINGLE_QUOTED))),
            new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
        ]);
        $botShape = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('kind'), false, new ConstTypeNode(new ConstExprStringNode('bot', ConstExprStringNode::SINGLE_QUOTED))),
            new ObjectShapeItemNode(new IdentifierTypeNode('name'), false, new IdentifierTypeNode('non-empty-string')),
        ]);
        $union = new UnionTypeNode([$userShape, $botShape]);

        $validUser = (object)['kind' => 'user', 'id' => 10];
        expect($this->validator->validate($validUser, $union, 'actor', $this->registry))->toBeNull();

        $badUser = (object)['kind' => 'user', 'id' => -10];
        $errUser = $this->validator->validate($badUser, $union, 'actor', $this->registry);
        expect($errUser)->toBeInstanceOf(ErrorMessage::class)
            ->and($errUser->getMessage())->toContain('actor->id')
        ;

        $badBot = (object)['kind' => 'bot', 'name' => ''];
        $errBot = $this->validator->validate($badBot, $union, 'actor', $this->registry);
        expect($errBot)->toBeInstanceOf(ErrorMessage::class)
            ->and($errBot->getMessage())->toContain('actor->name')
        ;
    });

    test('supports integer literal discriminators in array shapes', function () {
        $eventOne = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprIntegerNode('0'), false, new ConstTypeNode(new ConstExprIntegerNode('1'))),
            new ArrayShapeItemNode(new ConstExprIntegerNode('1'), false, new IdentifierTypeNode('positive-int')),
        ]);
        $eventTwo = ArrayShapeNode::createSealed([
            new ArrayShapeItemNode(new ConstExprIntegerNode('0'), false, new ConstTypeNode(new ConstExprIntegerNode('2'))),
            new ArrayShapeItemNode(new ConstExprIntegerNode('1'), false, new IdentifierTypeNode('string')),
        ]);
        $union = new UnionTypeNode([$eventOne, $eventTwo]);

        expect($this->validator->validate([1, 10], $union, 'tuple', $this->registry))->toBeNull();
        expect($this->validator->validate([2, 'text'], $union, 'tuple', $this->registry))->toBeNull();

        $err = $this->validator->validate([1, -10], $union, 'tuple', $this->registry);
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("tuple['1']")
        ;
    });

    test('validates fast-path class-string in union without string type', function () {
        $union = new UnionTypeNode([
            new IdentifierTypeNode('int'),
            new IdentifierTypeNode('class-string'),
        ]);

        expect($this->validator->validate(stdClass::class, $union, 'val', $this->registry))->toBeNull();
    });
});
