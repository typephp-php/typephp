<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Attributes;

class MeasurementSystemEntity
{
    /**
     * @var array<string, mixed>|null
     */
    #[OneToManyRelation(
        entity: 'measurement_display_unit',
        ref: 'measurement_system_id',
        onDelete: OnDeleteOption::CASCADE,
        api: true
    )]
    public ?array $units = null;
}
