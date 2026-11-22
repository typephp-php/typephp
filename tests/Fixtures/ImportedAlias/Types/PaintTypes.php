<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\ImportedAlias\Types;

use TypePHP\Tests\Fixtures\ImportedAlias\Enums\PaintColor;
use TypePHP\Tests\Fixtures\ImportedAlias\Enums\PaintColor as PaletteColor;

/**
 * @phpstan-type PaintArray array{color: PaintColor}
 * @phpstan-type AliasedPaintArray array{color: PaletteColor}
 * @phpstan-type BrushArray array{brush: Brush}
 * @phpstan-type DateArray array{at: \DateTimeImmutable}
 */
class PaintTypes
{
}
