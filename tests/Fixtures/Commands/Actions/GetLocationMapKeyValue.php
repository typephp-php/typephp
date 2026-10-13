<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Actions;

use TypePHP\Tests\Fixtures\Commands\Core\ValueCommand;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

/**
 * @extends ValueCommand<key-of<Location::STATUS_MAP>>
 */
final class GetLocationMapKeyValue extends ValueCommand
{
}
