<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\ImportedAlias\Repo;

use TypePHP\Tests\Fixtures\ImportedAlias\Types\PaintTypes;

/**
 * Deliberately does NOT import PaintColor or Brush.
 *
 * @phpstan-import-type PaintArray from PaintTypes
 * @phpstan-import-type AliasedPaintArray from PaintTypes
 * @phpstan-import-type BrushArray from PaintTypes
 */
final class PaintConsumer
{
    /**
     * @var PaintArray|null
     */
    public ?array $savedPaint = null;

    /**
     * @param PaintArray $paint
     */
    public function __construct(public array $paint)
    {
    }

    /**
     * @param AliasedPaintArray $paint
     *
     * @return AliasedPaintArray
     */
    public function acceptAliased(array $paint): array
    {
        return $paint;
    }

    /**
     * @param BrushArray $payload
     *
     * @return BrushArray
     */
    public function acceptSameNamespaceClass(array $payload): array
    {
        return $payload;
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return PaintArray
     */
    public function assignInlineVar(array $raw): array
    {
        /** @var PaintArray $paint */
        $paint = $raw;

        return $paint;
    }
}
