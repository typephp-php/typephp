<?php

declare(strict_types=1);

use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;

describe('RuntimeTypeChecker Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        Config::set([
            'inline_vars' => [
                'properties' => true,
                'generics' => true,
                'callables' => true,
                'scalars' => true,
                'arrays' => true,
                'objects' => true,
            ],
        ]);
    });

    afterEach(function () {
        Config::reset();
    });

    test('checkVariable validates scalar types', function () {
        $valid = RuntimeTypeChecker::checkVariable(10, 'positive-int', 'age', __FILE__);
        expect($valid)->toBe(10);

        $invalid = RuntimeTypeChecker::checkVariable(-5, 'positive-int', 'age', __FILE__);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class);
    });

    test('checkProperty validates property assignments', function () {
        $fixture = new ConfiguredProperty();

        $valid = RuntimeTypeChecker::checkProperty([1, 2, 3], $fixture, 'numbers', __FILE__);
        expect($valid)->toBe([1, 2, 3]);

        $invalid = RuntimeTypeChecker::checkProperty(['a'], $fixture, 'numbers', __FILE__);
        expect($invalid)->toBeInstanceOf(ErrorMessage::class);
    });

    test('checkSend returns send value or validates generator TSend', function () {
        $nullResult = RuntimeTypeChecker::checkSend('nonExistentFunc', null);
        expect($nullResult)->toBeNull();
    });

    test('checkYield returns yielded value', function () {
        $val = RuntimeTypeChecker::checkYield('nonExistentFunc', 'key', 'value');
        expect($val)->toBe('value');
    });
});
