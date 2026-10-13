<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Actions;

use TypePHP\Tests\Fixtures\Commands\Core\Command;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

/**
 * @extends Command<array{Location::KEY_STATUSES: list<Location::STATUS_*>}>
 */
final class GetLocationsWithConstantKey extends Command
{
}
