<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class WildcardConstantFixture
{
    public const VERSION_SELECTION_ALL = 'all';
    public const VERSION_SELECTION_BLUE_GREEN = 'blue-green';
    private const VERSION_SELECTION_INTERNAL = 'internal-mode';

    /**
     * @param self::VERSION_SELECTION_* $mode
     */
    public static function setVersionMode(string $mode): string
    {
        return $mode;
    }
}