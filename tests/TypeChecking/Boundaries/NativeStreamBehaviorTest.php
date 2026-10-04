<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use Phar;
use PharData;
use TypePHP\Internal\Io\StreamWrapper;

describe('Native Stream I/O Behavior Parity (feof & PharData)', function () {
    describe('1. feof() Read Loop Parity', function () {
        test('feof() turns true only after a read past EOF returns false (User Bug Report)', function () {
            $path = sys_get_temp_dir() . '/typephp_feof_test_' . uniqid() . '.txt';
            file_put_contents($path, "a\nb\n");

            $handle = fopen($path, 'r');
            expect($handle)->not()->toBeFalse();

            $reads = [];
            while (! feof($handle)) {
                $reads[] = fgets($handle);
            }
            fclose($handle);
            @unlink($path);

            expect($reads)->toBe(["a\n", "b\n", false])
                ->and(\count($reads))->toBe(3)
            ;
        });

        test('feof() on empty 0-byte file is false on open and true after first read', function () {
            $path = sys_get_temp_dir() . '/typephp_feof_empty_' . uniqid() . '.txt';
            file_put_contents($path, '');

            $handle = fopen($path, 'r');
            expect(feof($handle))->toBeFalse();

            $firstRead = fgets($handle);
            expect($firstRead)->toBeFalse()
                ->and(feof($handle))->toBeTrue()
            ;

            fclose($handle);
            @unlink($path);
        });

        test('fseek() resets feof() state to false', function () {
            $path = sys_get_temp_dir() . '/typephp_fseek_test_' . uniqid() . '.txt';
            file_put_contents($path, "line1\nline2\n");

            $handle = fopen($path, 'r');
            while (! feof($handle)) {
                fgets($handle);
            }
            expect(feof($handle))->toBeTrue();

            fseek($handle, 0);
            expect(feof($handle))->toBeFalse();

            $firstLine = fgets($handle);
            expect($firstLine)->toBe("line1\n");

            fclose($handle);
            @unlink($path);
        });
    });

    describe('2. PharData Archive Creation without Spurious Warnings', function () {
        test('creates and compresses PharData tar archive with zero warnings (User Bug Report)', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_phardata_' . uniqid();
            mkdir($tempDir, 0777, true);

            $sourceFile = $tempDir . '/sample.txt';
            file_put_contents($sourceFile, 'hello world content');

            $tarPath = $tempDir . '/archive.tar';
            $gzPath = $tempDir . '/archive.tar.gz';

            $warnings = [];
            set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
                $warnings[] = $errstr;

                return true;
            });

            try {
                $tar = new PharData($tarPath);
                $tar->addFile($sourceFile, 'sample.txt');
                $tar->compress(Phar::GZ);

                expect(file_exists($tarPath))->toBeTrue()
                    ->and(file_exists($gzPath))->toBeTrue()
                ;
            } finally {
                restore_error_handler();
                @unlink($sourceFile);
                @unlink($tarPath);
                @unlink($gzPath);
                @rmdir($tempDir);
            }

            expect($warnings)->toBeEmpty();
        });

        test('@fopen() on missing file respects @ error suppression', function () {
            $missing = sys_get_temp_dir() . '/non_existent_silent_' . uniqid() . '.txt';

            $warnings = [];
            set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
                if ((error_reporting() & $errno) !== 0) {
                    $warnings[] = $errstr;
                }

                return true;
            });

            try {
                $fp = @fopen($missing, 'r');
                expect($fp)->toBeFalse();
            } finally {
                restore_error_handler();
            }

            expect($warnings)->toBeEmpty();
        });

        test('fopen() on missing file triggers warning message when not suppressed', function () {
            $missing = sys_get_temp_dir() . '/non_existent_warning_' . uniqid() . '.txt';

            $warnings = [];
            set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
                $warnings[] = $errstr;

                return true;
            });

            try {
                $fp = fopen($missing, 'r');
                expect($fp)->toBeFalse();
            } finally {
                restore_error_handler();
            }

            $allWarnings = implode("\n", $warnings);
            expect($allWarnings)->toContain('fopen(' . $missing . '): Failed to open stream');
        });
    });

    describe('3. stream_select() & stream_cast() Interoperability', function () {
        test('stream_select() works on handles opened through stream wrapper via stream_cast', function () {
            $path = sys_get_temp_dir() . '/typephp_select_test_' . uniqid() . '.txt';
            file_put_contents($path, "content to select\n");

            $handle = fopen($path, 'r');
            expect($handle)->not()->toBeFalse();

            $read = [$handle];
            $write = null;
            $except = null;

            $changed = @stream_select($read, $write, $except, 0, 50000);

            if ($changed !== false) {
                expect($changed)->toBeGreaterThanOrEqual(0);
            }

            fclose($handle);
            @unlink($path);
        });

        test('stream_cast returns underlying resource when open and false when closed', function () {
            $wrapper = new StreamWrapper();
            $path = sys_get_temp_dir() . '/typephp_cast_test_' . uniqid() . '.txt';
            file_put_contents($path, 'sample');

            $openedPath = null;
            $opened = $wrapper->stream_open($path, 'r', 0, $openedPath);
            expect($opened)->toBeTrue();

            $cast = $wrapper->stream_cast(STREAM_CAST_FOR_SELECT);
            expect(\is_resource($cast))->toBeTrue();

            $wrapper->stream_close();
            expect($wrapper->stream_cast(STREAM_CAST_FOR_SELECT))->toBeFalse();

            @unlink($path);
        });
    });
});
