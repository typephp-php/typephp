<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

use TypePHP\Exception\TypeError;

interface RenamedConditionalInterface
{
    /**
     * @param bool $asInt
     * @param mixed $val
     *
     * @return ($asInt is true ? positive-int : non-empty-string)
     */
    public function formatRecord(bool $asInt, mixed $val): mixed;
}

class RenamedConditionalService implements RenamedConditionalInterface
{
    /**
     * Renames $asInt -> $formatAsInteger
     */
    public function formatRecord(bool $formatAsInteger, mixed $val): mixed
    {
        return $val;
    }
}

describe('Inherited Conditional Returns with Renamed Parameters', function () {
    test('resolves conditional return based on positional argument when parameter is renamed in child', function () {
        $service = new RenamedConditionalService();
        expect($service->formatRecord(true, 42))->toBe(42);
        expect($service->formatRecord(false, 'active_record'))->toBe('active_record');

        expect(fn () => $service->formatRecord(true, -5))
            ->toThrow(TypeError::class, 'positive-int')
        ;
        expect(fn () => $service->formatRecord(false, ''))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });
});
