<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\ImportedAlias\Child;

use TypePHP\Tests\Fixtures\ImportedAlias\Types\PaintTypes;

/**
 * Deliberately does NOT import PaintColor.
 *
 * @phpstan-type ExtendedPaint array{paint: PaintArray, qty: positive-int}
 */
final class PaintChildTypes extends PaintTypes
{
    /**
     * @param ExtendedPaint $data
     *
     * @return ExtendedPaint
     */
    public function acceptExtended(array $data): array
    {
        return $data;
    }
}
