<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Actions;

use TypePHP\Tests\Fixtures\Commands\Core\HasLocationOptions;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

/**
 * @use HasLocationOptions<array{statuses: list<Location::STATUS_*>}>
 */
final class ClassLevelTraitLocationConsumer
{
    use HasLocationOptions;
}
