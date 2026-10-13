<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Actions;

use TypePHP\Tests\Fixtures\Commands\Core\Command;

/**
 * @extends Command<array{statuses: list<\TypePHP\Tests\Fixtures\Commands\Model\Location::STATUS_*>}>
 */
final class GetLocationsFqcn extends Command
{
}
