<?php

declare(strict_types=1);

use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;

test('error factory creates an ErrorMessage value object', function () {
    $err = ErrorFactory::createError('Test argument error message');

    expect($err)->toBeInstanceOf(ErrorMessage::class);
    expect($err->getMessage())->toBe('Test argument error message');
});

test('prepareException converts standard TypeError into ExactTypeError with caller trace details', function () {
    $err = new TypeError('Test parameter failure');
    $prepared = ErrorFactory::prepareException($err);

    expect($prepared)->toBeInstanceOf(TypeError::class);
});

test('does not corrupt user string literals containing the word given in return error messages', function () {
    $rawMsg = "getOrderDiscount(): Return value must be of type positive-int, string 'discount given to customer' given";
    $err = ErrorFactory::createError($rawMsg);

    expect($err->getMessage())->toBe("getOrderDiscount(): Return value must be of type positive-int, string 'discount given to customer' returned");
});
