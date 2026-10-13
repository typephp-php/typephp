<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Actions;

use TypePHP\Tests\Fixtures\Commands\Core\CommandInterface;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

/**
 * @implements CommandInterface<array{statuses: list<Location::STATUS_*>}>
 */
final class ExecuteLocationsCommand implements CommandInterface
{
    public function execute(array $options): array
    {
        return $options;
    }
}
