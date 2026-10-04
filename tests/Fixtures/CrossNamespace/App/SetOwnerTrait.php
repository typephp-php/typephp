<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\CrossNamespace\App;

use TypePHP\Tests\Fixtures\CrossNamespace\Model\CrossNamespaceUser;

trait SetOwnerTrait
{
    public function setOwner(): void
    {
        $this->owner = new CrossNamespaceUser();
    }
}