<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\ObjectShapeValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

class ObjectShapeTestUser
{
    public int $id = 10;
    public string $name = 'Alice';
}

class ObjectShapeUninitClass
{
    public int $id;
    public string $uninitOptional;
}

class ObjectShapeMagicClass
{
    private array $data = [
        'magicProp' => 'valid_string',
        'badMagicProp' => 12345,
    ];

    public function __isset(string $name): bool
    {
        return isset($this->data[$name]);
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }
}

describe('ObjectShapeValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new ObjectShapeValidator();
    });

    test('rejects non-object inputs', function () {
        $shape = new ObjectShapeNode([]);

        $err = $this->validator->validate('not_an_object', $shape, 'target', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('must be of type object')
        ;
    });

    test('validates stdClass instances', function () {
        $shape = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
            new ObjectShapeItemNode(new IdentifierTypeNode('role'), true, new IdentifierTypeNode('string')),
        ]);

        $valid = (object) ['id' => 10, 'role' => 'admin'];
        expect($this->validator->validate($valid, $shape, 'target', $this->registry))->toBeNull();

        $validOptionalOmitted = (object) ['id' => 10];
        expect($this->validator->validate($validOptionalOmitted, $shape, 'target', $this->registry))->toBeNull();

        $missingRequired = (object) ['role' => 'admin'];
        $errMissing = $this->validator->validate($missingRequired, $shape, 'target', $this->registry);
        expect($errMissing)->toBeInstanceOf(ErrorMessage::class)
            ->and($errMissing->getMessage())->toContain("missing required property 'id'")
        ;

        $invalidValue = (object) ['id' => -1];
        $errInvalid = $this->validator->validate($invalidValue, $shape, 'target', $this->registry);
        expect($errInvalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($errInvalid->getMessage())->toContain('target->id')
        ;
    });

    test('validates custom class instances with required and optional missing properties', function () {
        $user = new ObjectShapeTestUser();

        $shapeValid = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('positive-int')),
            new ObjectShapeItemNode(new IdentifierTypeNode('name'), false, new IdentifierTypeNode('string')),
            new ObjectShapeItemNode(new IdentifierTypeNode('missingOptional'), true, new IdentifierTypeNode('string')),
        ]);
        expect($this->validator->validate($user, $shapeValid, 'target', $this->registry))->toBeNull();

        $shapeMissingRequired = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('missingRequired'), false, new IdentifierTypeNode('string')),
        ]);
        $errMissing = $this->validator->validate($user, $shapeMissingRequired, 'target', $this->registry);
        expect($errMissing)->toBeInstanceOf(ErrorMessage::class)
            ->and($errMissing->getMessage())->toContain("missing required property 'missingRequired'")
        ;

        $shapeInvalid = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('negative-int')),
        ]);
        $errInvalid = $this->validator->validate($user, $shapeInvalid, 'target', $this->registry);
        expect($errInvalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($errInvalid->getMessage())->toContain('target->id')
        ;
    });

    test('validates uninitialized properties on custom class instances', function () {
        $obj = new ObjectShapeUninitClass();

        $shapeRequiredUninit = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('id'), false, new IdentifierTypeNode('int')),
        ]);
        $errRequired = $this->validator->validate($obj, $shapeRequiredUninit, 'target', $this->registry);
        expect($errRequired)->toBeInstanceOf(ErrorMessage::class)
            ->and($errRequired->getMessage())->toContain("property 'id' is uninitialized")
        ;

        $shapeOptionalUninit = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('uninitOptional'), true, new IdentifierTypeNode('string')),
        ]);
        expect($this->validator->validate($obj, $shapeOptionalUninit, 'target', $this->registry))->toBeNull();
    });

    test('validates properties resolved through magic getters on custom class instances', function () {
        $magicObj = new ObjectShapeMagicClass();

        $shapeValid = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('magicProp'), false, new IdentifierTypeNode('string')),
        ]);
        expect($this->validator->validate($magicObj, $shapeValid, 'target', $this->registry))->toBeNull();

        $shapeInvalid = new ObjectShapeNode([
            new ObjectShapeItemNode(new IdentifierTypeNode('badMagicProp'), false, new IdentifierTypeNode('string')),
        ]);
        $errInvalid = $this->validator->validate($magicObj, $shapeInvalid, 'target', $this->registry);
        expect($errInvalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($errInvalid->getMessage())->toContain('target->badMagicProp')
        ;
    });
});