<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Shopware\Config;

use TypePHP\Tests\Fixtures\Shopware\Metric\Type;

/**
 * @phpstan-import-type MetricTypeValues from Type
 *
 * @phpstan-type MetricDefinition array{
 *    type: MetricTypeValues,
 *    description: string
 * }
 */
class MetricConfigProvider
{
    /**
     * @param array<string, MetricDefinition> $definitions
     */
    public function __construct(array $definitions)
    {
        // Constructor logic...
    }
}
