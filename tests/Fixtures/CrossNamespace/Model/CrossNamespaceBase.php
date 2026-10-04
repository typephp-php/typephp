<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\CrossNamespace\Model;

class CrossNamespaceBase
{
    /**
     * @var CrossNamespaceUser|null
     */
    public ?object $owner = null;
}