<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class EnumWildcard
{
    /**
     * @param StatusEnum::* $status
     */
    public static function processEnumCase(StatusEnum $status): StatusEnum
    {
        return $status;
    }

    /**
     * @param StatusEnum::ACT* $status
     */
    public static function processPrefixEnumCase(StatusEnum $status): StatusEnum
    {
        return $status;
    }
}
