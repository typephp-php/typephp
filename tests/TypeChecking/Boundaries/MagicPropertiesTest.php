<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Types\MagicPropertyFixture;

beforeEach(function () {
    Config::reset();
});

afterEach(function () {
    Config::reset();
});

/**
 * Fixture testing dynamic property reads and writes
 *
 * @property-read string $status
 * @property-write string $name
 * @property positive-int $score
 */
class DynamicModelFixture
{
    public array $data = [];

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }
}

/**
 * Class with ignore tag on __get
 *
 * @property-read positive-int $code
 */
class IgnoredGetModelFixture
{
    /**
     * @typephp-ignore
     */
    public function __get(string $name): mixed
    {
        return -999;
    }
}

describe('Class-Level Magic Properties (@property, @property-read, @property-write)', function () {
    describe('Property Writes (__set) [Default: Enabled]', function () {
        test('validates incoming values on property assignment via __set', function () {
            $fixture = new MagicPropertyFixture();

            $fixture->magicScore = 100;
            expect($fixture->data['magicScore'])->toBe(100);

            expect(fn () => $fixture->magicScore = -5)
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\Types\MagicPropertyFixture::$magicScore must be of type positive-int')
            ;
        });

        test('validates @property-write on property assignment', function () {
            $fixture = new MagicPropertyFixture();

            $fixture->magicName = 'Alice';
            expect($fixture->data['magicName'])->toBe('Alice');

            expect(fn () => $fixture->magicName = '')
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\Types\MagicPropertyFixture::$magicName must be of type non-empty-string')
            ;
        });

        test('bypasses property write validation when write is disabled in config', function () {
            Config::set([
                'magic_properties' => [
                    'write' => false,
                ],
            ]);

            $fixture = new MagicPropertyFixture();
            $fixture->magicScore = -999;

            expect($fixture->data['magicScore'])->toBe(-999);
        });
    });

    describe('Property Reads (__get) [Default: Disabled to prevent false positives]', function () {
        test('by default, reading an unpopulated property returning null passes without error', function () {
            $model = new DynamicModelFixture();

            $status = $model->status;
            expect($status)->toBeNull();
        });

        test('by default, reading a mismatched type via __get passes without error', function () {
            $model = new DynamicModelFixture();
            $model->data['status'] = 12345;

            expect($model->status)->toBe(12345);
        });
    });

    describe('Property Reads (__get) [Opt-in via magic_properties.read => true]', function () {
        beforeEach(function () {
            Config::set([
                'magic_properties' => [
                    'write' => true,
                    'read' => true,
                ],
            ]);
        });

        afterEach(function () {
            Config::reset();
        });

        test('accepts valid property read matching @property-read string type', function () {
            $model = new DynamicModelFixture();
            $model->data['status'] = 'active';

            expect($model->status)->toBe('active');
        });

        test('throws TypeError when dynamic property read returns null for non-nullable @property-read', function () {
            $model = new DynamicModelFixture();

            expect(fn () => $model->status)
                ->toThrow(TypeError::class, 'Property DynamicModelFixture::$status must be of type string, null returned')
            ;
        });

        test('throws TypeError when dynamic property read returns invalid integer', function () {
            $model = new DynamicModelFixture();
            $model->data['status'] = 12345;

            expect(fn () => $model->status)
                ->toThrow(TypeError::class, 'Property DynamicModelFixture::$status must be of type string, int (12345) returned')
            ;
        });

        test('passes cleanly when reading dynamic property with no docblock annotation', function () {
            $model = new DynamicModelFixture();
            $model->data['unannotatedCustomProp'] = [1, 2, 3];

            expect($model->unannotatedCustomProp)->toBe([1, 2, 3]);
        });

        test('validates read on standard @property annotation as well', function () {
            $model = new DynamicModelFixture();
            $model->data['score'] = 50;

            expect($model->score)->toBe(50);

            $model->data['score'] = -10;
            expect(fn () => $model->score)
                ->toThrow(TypeError::class, 'Property DynamicModelFixture::$score must be of type positive-int, negative int (-10) returned')
            ;
        });

        test('suppresses read validation when __get is annotated with @typephp-ignore', function () {
            $ignored = new IgnoredGetModelFixture();

            expect($ignored->code)->toBe(-999);
        });
    });
});
