<?php

declare(strict_types=1);

use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Util\Config;

function resetTypePHPTestEnvironment(): void
{
    putenv('TYPEPHP_AUTO_BOOT');
    putenv('TYPEPHP_AUTO_BOOT=');
    putenv('TYPEPHP_ON_VIOLATION');
    putenv('TYPEPHP_ON_VIOLATION=');
    putenv('TYPEPHP_REPORT_FILE');
    putenv('TYPEPHP_REPORT_FILE=');
    putenv('TYPEPHP_FAIL_ON_REPORT');
    putenv('TYPEPHP_FAIL_ON_REPORT=');

    unset(
        $_ENV['TYPEPHP_AUTO_BOOT'],
        $_SERVER['TYPEPHP_AUTO_BOOT'],
        $_ENV['TYPEPHP_ON_VIOLATION'],
        $_SERVER['TYPEPHP_ON_VIOLATION'],
        $_ENV['TYPEPHP_REPORT_FILE'],
        $_SERVER['TYPEPHP_REPORT_FILE'],
        $_ENV['TYPEPHP_FAIL_ON_REPORT'],
        $_SERVER['TYPEPHP_FAIL_ON_REPORT']
    );

    ViolationCollector::reset();
    Config::reset();
}

uses()
    ->beforeEach(function () {
        resetTypePHPTestEnvironment();
    })
    ->afterEach(function () {
        resetTypePHPTestEnvironment();
    })
    ->in(__DIR__)
;
