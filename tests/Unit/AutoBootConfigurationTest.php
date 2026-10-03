<?php

declare(strict_types=1);

namespace TypePHP\Tests\Unit;

use ReflectionClass;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;
use TypePHP\TypePHP;

describe('Auto-Boot Configuration & Manual Booting', function () {
    afterEach(function () {
        putenv('TYPEPHP_AUTO_BOOT');
        Config::reset();
    });

    test('manual TypePHP::boot() works as intended after auto-boot is disabled', function () {
        Config::set(['auto_boot' => false]);
        expect(Config::isAutoBootEnabled())->toBeFalse();

        TypePHP::boot();

        expect(StreamWrapper::isRegistered())->toBeTrue();
    });

    test('isAutoBootEnabled handles boolean strings in composer.json', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_autoboot_string_' . uniqid();
        mkdir($tempDir, 0777, true);

        $composerJsonContent = json_encode([
            'name' => 'test/autoboot-string',
            'extra' => [
                'typephp' => [
                    'auto-boot' => 'false',
                ],
            ],
        ]);
        file_put_contents($tempDir . '/composer.json', $composerJsonContent);

        try {
            Config::reset();

            $ref = new ReflectionClass(Config::class);
            $prop = $ref->getProperty('projectRoot');
            $prop->setValue(null, $tempDir);

            expect(Config::isAutoBootEnabled())->toBeFalse();
        } finally {
            @unlink($tempDir . '/composer.json');
            @rmdir($tempDir);
            Config::reset();
        }
    });

    test('isAutoBootEnabled handles true in composer.json', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_autoboot_true_' . uniqid();
        mkdir($tempDir, 0777, true);

        $composerJsonContent = json_encode([
            'name' => 'test/autoboot-true',
            'extra' => [
                'typephp' => [
                    'auto-boot' => true,
                ],
            ],
        ]);
        file_put_contents($tempDir . '/composer.json', $composerJsonContent);

        try {
            Config::reset();

            $ref = new ReflectionClass(Config::class);
            $prop = $ref->getProperty('projectRoot');
            $prop->setValue(null, $tempDir);

            expect(Config::isAutoBootEnabled())->toBeTrue();
        } finally {
            @unlink($tempDir . '/composer.json');
            @rmdir($tempDir);
            Config::reset();
        }
    });

    test('bypasses json parsing completely if composer.json does not contain auto-boot keyword', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_autoboot_skip_' . uniqid();
        mkdir($tempDir, 0777, true);

        $composerJsonContent = json_encode([
            'name' => 'test/autoboot-skip',
            'require' => ['php' => '^8.1'],
        ]);
        file_put_contents($tempDir . '/composer.json', $composerJsonContent);

        try {
            Config::reset();

            $ref = new ReflectionClass(Config::class);
            $prop = $ref->getProperty('projectRoot');
            $prop->setValue(null, $tempDir);

            expect(Config::isAutoBootEnabled())->toBeTrue();
        } finally {
            @unlink($tempDir . '/composer.json');
            @rmdir($tempDir);
            Config::reset();
        }
    });
});
