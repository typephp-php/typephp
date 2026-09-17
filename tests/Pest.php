<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;

uses()
    ->beforeEach(function () {
        Config::reset();
    })
    ->afterEach(function () {
        Config::reset();
    })
    ->in('Contract', 'Internal', 'Feature')
;
