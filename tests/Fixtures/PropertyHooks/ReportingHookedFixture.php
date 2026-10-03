<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\PropertyHooks;

class ReportingHookedFixture
{
    /**
     * @var positive-int
     */
    public int $hookedScore = 10 {
        get => -99;
        set(int $value) {
            $this->hookedScore = $value;
        }
    }
}
