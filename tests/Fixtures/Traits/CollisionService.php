<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits;

class CollisionService
{
    use SecondLogger, FirstLogger {
        FirstLogger::log insteadof SecondLogger;
        SecondLogger::log as backupLog;
    }
}