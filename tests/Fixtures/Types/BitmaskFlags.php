<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class BitmaskFlags
{
    public const FLAG_READ = 1;      // 0001
    public const FLAG_WRITE = 2;     // 0010
    public const FLAG_EXECUTE = 4;   // 0100

    /**
     * @param int-mask<1, 2, 4> $mask
     */
    public static function checkLiteralMask(int $mask): int
    {
        return $mask;
    }

    /**
     * @param int-mask-of<self::FLAG_*> $mask
     */
    public static function checkWildcardMask(int $mask): int
    {
        return $mask;
    }
}
