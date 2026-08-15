<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

class ChildComboService extends ComboAbstractParent
{
    public function setKey(mixed $key): bool
    {
        return true;
    }
}
