<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\ImportedAlias\Repo;

use TypePHP\Tests\Fixtures\ImportedAlias\Types\Brush as DateTimeImmutable;
use TypePHP\Tests\Fixtures\ImportedAlias\Types\PaintTypes;

/**
 * Imports DateArray (which uses root \DateTimeImmutable), while this file
 * aliases Brush as DateTimeImmutable.
 *
 * @phpstan-import-type DateArray from PaintTypes
 */
final class ShadowedAliasConsumer
{
    /**
     * @param DateArray $payload
     *
     * @return DateArray
     */
    public function acceptDate(array $payload): array
    {
        return $payload;
    }

    public function dummyShadowReference(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
