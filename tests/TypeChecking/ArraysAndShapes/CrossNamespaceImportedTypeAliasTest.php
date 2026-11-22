<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

use DateTimeImmutable;
use stdClass;
use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\ImportedAlias\Child\PaintChildTypes;
use TypePHP\Tests\Fixtures\ImportedAlias\Enums\PaintColor;
use TypePHP\Tests\Fixtures\ImportedAlias\Repo\PaintConsumer;
use TypePHP\Tests\Fixtures\ImportedAlias\Repo\ShadowedAliasConsumer;
use TypePHP\Tests\Fixtures\ImportedAlias\Types\Brush;

describe('Cross-Namespace @phpstan-import-type Resolution (Bug Report TDD)', function () {
    describe('1. Direct Bug Report Reproduction (v0.11.2 vs v0.14.x)', function () {
        test('resolves enum imported in declaring file when consumer file does not import it', function () {
            $consumer = new PaintConsumer(['color' => PaintColor::Red]);

            expect($consumer->paint)->toBe(['color' => PaintColor::Red]);
        });

        test('rejects invalid value with resolved FQCN of the declaring file enum', function () {
            expect(fn () => new PaintConsumer(['color' => new stdClass()]))
                ->toThrow(TypeError::class, "Argument \$paint['color'] must be of type " . PaintColor::class)
            ;
        });

        test('resolves aliased use import (use PaintColor as PaletteColor) in declaring file', function () {
            $consumer = new PaintConsumer(['color' => PaintColor::Red]);

            expect($consumer->acceptAliased(['color' => PaintColor::Blue]))
                ->toBe(['color' => PaintColor::Blue])
            ;
        });

        test('resolves same-namespace class in declaring file without consumer importing it', function () {
            $consumer = new PaintConsumer(['color' => PaintColor::Red]);
            $brush = new Brush();

            expect($consumer->acceptSameNamespaceClass(['brush' => $brush]))
                ->toBe(['brush' => $brush])
            ;
        });

        test('resolves imported cross-namespace alias on class property and inline @var', function () {
            $consumer = new PaintConsumer(['color' => PaintColor::Red]);

            $consumer->savedPaint = ['color' => PaintColor::Blue];
            expect($consumer->savedPaint)->toBe(['color' => PaintColor::Blue]);

            expect($consumer->assignInlineVar(['color' => PaintColor::Red]))
                ->toBe(['color' => PaintColor::Red])
            ;

            expect(fn () => $consumer->assignInlineVar(['color' => 'red']))
                ->toThrow(TypeError::class, PaintColor::class)
            ;
        });
    });

    describe('2. Related Edge Cases (Expected Red Phase on v0.14.1)', function () {
        test('resolves parent alias imports when referenced by a child class @phpstan-type in another namespace', function () {
            $child = new PaintChildTypes();

            $payload = [
                'paint' => ['color' => PaintColor::Red],
                'qty' => 2,
            ];

            expect($child->acceptExtended($payload))->toBe($payload);
        });

        test('does not re-resolve root-namespace class in imported alias against a shadowing use-alias in consumer', function () {
            $consumer = new ShadowedAliasConsumer();
            $now = new DateTimeImmutable();

            expect($consumer->acceptDate(['at' => $now]))
                ->toBe(['at' => $now])
            ;
        });
    });
});
