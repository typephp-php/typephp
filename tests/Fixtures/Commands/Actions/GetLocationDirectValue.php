<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Actions;

use TypePHP\Tests\Fixtures\Commands\Core\ValueCommand;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

/**
 * @extends ValueCommand<Location::STATUS_*>
 */
final class GetLocationDirectValue extends ValueCommand
{
}
