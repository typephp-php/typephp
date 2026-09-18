<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Probe;

use TypePHP\Tests\Fixtures\Probe\Sub\HasRoute;

enum UnparameterizedRoute: string implements RouteInterface
{
    use HasRoute;

    case A = 'a';
    case B = 'b';
}