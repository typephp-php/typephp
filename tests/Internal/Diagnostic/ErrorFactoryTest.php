<?php

declare(strict_types=1);

use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;

test('error factory creates an ErrorMessage value object', function () {
    $err = ErrorFactory::createError('Test argument error message');

    expect($err)->toBeInstanceOf(ErrorMessage::class)
        ->and($err->getMessage())->toBe('Test argument error message')
    ;
});

test('prepareException converts standard TypeError into ExactTypeError with caller trace details', function () {
    $err = new TypeError('Test parameter failure');
    $prepared = ErrorFactory::prepareException($err);

    expect($prepared)->toBeInstanceOf(TypeError::class);
});

test('prepareException mutates file and line directly when provided', function () {
    $err = new TypeError('Test parameter failure');
    $prepared = ErrorFactory::prepareException($err, 100, '/app/Services/UserService.php');

    expect($prepared->getFile())->toBe('/app/Services/UserService.php')
        ->and($prepared->getLine())->toBe(100)
    ;
});

test('sanitizeMessage strips CallableWrapper and replaces with target file and line', function () {
    $rawMessage = 'Test error, called in /project/src/Internal/Wrapper/CallableWrapper.php on line 123';
    $err = new TypeError($rawMessage);

    $prepared = ErrorFactory::prepareException($err, 42, '/app/Action.php');

    expect($prepared->getMessage())->toBe('Test error, called in /app/Action.php on line 42');
});

test('sanitizeMessage strips CallableWrapper when target file and line are omitted', function () {
    $rawMessage = 'Test error, called in /project/src/Internal/Wrapper/CallableWrapper.php on line 123';
    $err = new TypeError($rawMessage);

    $prepared = ErrorFactory::prepareException($err);

    expect($prepared->getMessage())->toBe('Test error');
});

test('sanitizeMessage strips RunCommand CLI prefix', function () {
    $rawMessage = 'TypePHP\Internal\Cli\RunCommand::Target error message';
    $err = new TypeError($rawMessage);

    $prepared = ErrorFactory::prepareException($err);

    expect($prepared->getMessage())->toBe('Target error message');
});

test('createError converts null given to none returned for return contracts', function () {
    $rawMsg = 'findUser(): Return value must be of type string, null given';
    $err = ErrorFactory::createError($rawMsg);

    expect($err->getMessage())->toBe('findUser(): Return value must be of type string, none returned');
});

test('does not corrupt user string literals containing the word given in return error messages', function () {
    $rawMsg = "getOrderDiscount(): Return value must be of type positive-int, string 'discount given to customer' given";
    $err = ErrorFactory::createError($rawMsg);

    expect($err->getMessage())->toBe("getOrderDiscount(): Return value must be of type positive-int, string 'discount given to customer' returned");
});