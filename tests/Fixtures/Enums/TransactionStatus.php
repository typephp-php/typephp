<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Enums;

enum TransactionStatus: int
{
    case PENDING = 1;
    case COMPLETED = 2;
    case FAILED = 3;
}