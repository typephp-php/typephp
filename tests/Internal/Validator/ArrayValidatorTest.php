<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\ArrayValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

describe('ArrayValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new ArrayValidator();
        $this->intNode = new ArrayTypeNode(new IdentifierTypeNode('int'));
        $this->posIntNode = new ArrayTypeNode(new IdentifierTypeNode('positive-int'));
    });

    afterEach(function () {
        Config::reset();
    });

    test('validates valid and empty arrays', function () {
        expect($this->validator->validate([], $this->intNode, 'arg', $this->registry))->toBeNull()
            ->and($this->validator->validate([1, 2, 3], $this->intNode, 'arg', $this->registry))->toBeNull()
        ;
    });

    test('rejects non-array and non-traversable values', function () {
        $err = $this->validator->validate('not_an_array', $this->intNode, 'arg', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('must be of type array')
        ;

        $errObj = $this->validator->validate(new stdClass(), $this->intNode, 'arg', $this->registry);
        expect($errObj)->toBeInstanceOf(ErrorMessage::class);
    });

    test('bypasses generator instances directly', function () {
        $gen = (function () {
            yield 1;
        })();

        expect($this->validator->validate($gen, $this->intNode, 'arg', $this->registry))->toBeNull();
    });

    test('catches invalid items in exhaustive scan with string and integer keys', function () {
        $badList = [1, 'invalid', 3];
        $err = $this->validator->validate($badList, $this->intNode, 'items', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('items[1]')
        ;

        $badAssoc = ['first' => 10, 'second' => 'invalid'];
        $errAssoc = $this->validator->validate($badAssoc, $this->intNode, 'items', $this->registry);

        expect($errAssoc)->toBeInstanceOf(ErrorMessage::class)
            ->and($errAssoc->getMessage())->toContain("items['second']")
        ;
    });

    test('executes hybrid sampling on large sequential lists (> 128 elements)', function () {
        Config::set(['array_validation' => 'hybrid']);

        $largeList = range(1, 150);
        expect($this->validator->validate($largeList, $this->posIntNode, 'large', $this->registry))->toBeNull();

        $badBoundaryList = $largeList;
        $badBoundaryList[0] = -5;
        $err = $this->validator->validate($badBoundaryList, $this->posIntNode, 'large', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('large[0]')
        ;
    });

    test('executes hybrid sampling on large associative arrays (> 128 elements)', function () {
        Config::set(['array_validation' => 'hybrid']);

        $largeAssoc = [];
        for ($i = 0; $i < 150; $i++) {
            $largeAssoc["key_{$i}"] = $i + 1;
        }

        expect($this->validator->validate($largeAssoc, $this->posIntNode, 'map', $this->registry))->toBeNull();

        $badAssoc = $largeAssoc;
        $badAssoc['key_0'] = -99;
        $err = $this->validator->validate($badAssoc, $this->posIntNode, 'map', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("map['key_0']")
        ;
    });

    test('validates non-array Traversable objects', function () {
        $iterator = new ArrayIterator([10, 20, 30]);
        expect($this->validator->validate($iterator, $this->intNode, 'iter', $this->registry))->toBeNull();

        $badIterator = new ArrayIterator(['foo' => 'not_int']);
        $err = $this->validator->validate($badIterator, $this->intNode, 'iter', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain("iter['foo']")
        ;

        $badIntKeyIterator = new ArrayIterator([0 => 'not_int']);
        $errInt = $this->validator->validate($badIntKeyIterator, $this->intNode, 'iter', $this->registry);

        expect($errInt)->toBeInstanceOf(ErrorMessage::class)
            ->and($errInt->getMessage())->toContain('iter[0]')
        ;
    });
});