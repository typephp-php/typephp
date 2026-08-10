<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Attributes;

enum OnDeleteOption
{
    case CASCADE;
    case NO_ACTION;
}
