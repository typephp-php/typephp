<?php

declare(strict_types=1);

use TypePHP\Internal\Diagnostic\TypeFormatter;

test('formats negative integer correctly', function () {
    expect(TypeFormatter::formatGivenValue(-10))->toBe('negative int (-10)');
});

test('formats zero integer correctly', function () {
    expect(TypeFormatter::formatGivenValue(0))->toBe('zero int (0)');
});

test('formats positive integer correctly', function () {
    expect(TypeFormatter::formatGivenValue(42))->toBe('int (42)');
});

test('formats float correctly', function () {
    expect(TypeFormatter::formatGivenValue(12.34))->toBe('float (12.34)');
});

test('formats empty string correctly', function () {
    expect(TypeFormatter::formatGivenValue(''))->toBe("empty string ('')");
});

test('formats regular string correctly', function () {
    expect(TypeFormatter::formatGivenValue('hello'))->toBe("string 'hello'");
});

test('formats long string with truncation', function () {
    $longString = 'this_is_a_very_long_string_exceeding_twenty_chars';
    expect(TypeFormatter::formatGivenValue($longString))->toBe("string 'this_is_a_very_lo...'");
});

test('formats empty array correctly', function () {
    expect(TypeFormatter::formatGivenValue([]))->toBe('empty array ([])');
});

test('formats sequential list correctly', function () {
    expect(TypeFormatter::formatGivenValue(['a', 'b', 'c']))->toBe('list (3 items)');
});

test('formats associative array correctly', function () {
    expect(TypeFormatter::formatGivenValue(['id' => 1, 'name' => 'Alice']))->toBe("associative array (key 'id')");
});

test('formats non-sequential array correctly', function () {
    expect(TypeFormatter::formatGivenValue([1 => 'a', 2 => 'b']))->toBe('non-sequential array (index 1)');
});

test('formats booleans correctly', function () {
    expect(TypeFormatter::formatGivenValue(true))->toBe('bool (true)');
    expect(TypeFormatter::formatGivenValue(false))->toBe('bool (false)');
});

test('formats sensitive parameter values without leaking content', function () {
    expect(TypeFormatter::formatGivenValue('super_secret_password', isSensitive: true))->toBe('string');
    expect(TypeFormatter::formatGivenValue(12345, isSensitive: true))->toBe('int');
});

test('formats object instances using get_debug_type fallback', function () {
    expect(TypeFormatter::formatGivenValue(new stdClass()))->toBe('stdClass');
});