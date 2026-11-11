<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Constants in traits need PHP 8.2+, so this lives outside the test file and is only autoloaded there.
 */
trait TraitWithConstant
{
    public const TRAIT_KEY = 'trait_action';
}
