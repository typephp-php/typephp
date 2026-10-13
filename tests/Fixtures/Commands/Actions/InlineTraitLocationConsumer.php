<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Actions;

use TypePHP\Tests\Fixtures\Commands\Core\HasLocationOptions;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

final class InlineTraitLocationConsumer
{
    /**
     * @use HasLocationOptions<array{statuses: list<Location::STATUS_*>}>
     */
    use HasLocationOptions;
}
