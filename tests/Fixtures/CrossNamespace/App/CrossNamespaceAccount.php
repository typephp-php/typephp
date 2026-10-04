<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\CrossNamespace\App;

use TypePHP\Tests\Fixtures\CrossNamespace\Model\CrossNamespaceBase;

class CrossNamespaceAccount extends CrossNamespaceBase
{
    use SetOwnerTrait;
}
