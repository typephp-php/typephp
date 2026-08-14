<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

class ChildShiftedTraitAppService implements ShiftedTraitInterface
{
    use ShiftedFulfillmentTrait;
}
