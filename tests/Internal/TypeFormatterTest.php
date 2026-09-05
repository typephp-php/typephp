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

test('formats booleans correctly', function () {
    expect(TypeFormatter::formatGivenValue(true))->toBe('bool (true)');
    expect(TypeFormatter::formatGivenValue(false))->toBe('bool (false)');
});
