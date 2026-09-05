<?php

declare(strict_types=1);

use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\FileFilter;

describe('StreamWrapper Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    afterEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    describe('transformSource()', function () {
        test('transforms functions and injects RuntimeTypeChecker checks', function () {
            $source = <<<'PHP'
<?php

/**
 * @param positive-int $id
 */
function testUser(int $id): bool
{
    return true;
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_sample.php');

            expect($transformed)->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->toContain('testUser')
            ;
        });

        test('returns raw source unchanged if source is not valid PHP', function () {
            $invalidSource = '<?php invalid php syntax {{{';

            $transformed = StreamWrapper::transformSource($invalidSource, 'bad.php');

            expect($transformed)->toBe($invalidSource);
        });

        test('wraps yield expressions in generator functions', function () {
            $genSource = <<<'PHP'
<?php

/**
 * @return Generator<string, positive-int>
 */
function testGen(): Generator
{
    yield 'a' => 10;
}
PHP;

            $transformed = StreamWrapper::transformSource($genSource, 'gen.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkYield')
                ->and($transformed)->toContain('RuntimeTypeChecker::checkSend')
            ;
        });

        test('respects @typephp-ignore-file docblock suppression tag', function () {
            $source = <<<'PHP'
<?php

/**
 * @typephp-ignore-file
 */

/**
 * @param positive-int $id
 */
function testIgnoredFileFunc(int $id): int
{
    return $id;
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'ignored_file.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->toBe($source)
            ;
        });
    });

    describe('url_stat(), Symlinks & Cache Invalidation', function () {
        test('caches positive stat results in memory for .php source files', function () {
            $wrapper = new StreamWrapper();
            $existingFile = __FILE__;

            $stat1 = $wrapper->url_stat($existingFile, 0);
            $stat2 = $wrapper->url_stat($existingFile, 0);

            expect($stat1)->toBeArray()
                ->and($stat1)->toBe($stat2)
            ;
        });

        test('differentiates between stat() and lstat() cache keys for symlinks', function () {
            $wrapper = new StreamWrapper();
            $tempDir = sys_get_temp_dir() . '/typephp_symlink_test_' . uniqid();
            mkdir($tempDir, 0777, true);

            $targetFile = $tempDir . '/target.php';
            $linkFile = $tempDir . '/link.php';

            file_put_contents($targetFile, '<?php // target file');

            $symlinkCreated = false;

            try {
                $symlinkCreated = @symlink($targetFile, $linkFile);
            } catch (Throwable $e) {
                // Windows without developer mode may disallow symlinks
            }

            if ($symlinkCreated) {
                try {
                    $statResult = $wrapper->url_stat($linkFile, 0);
                    $lstatResult = $wrapper->url_stat($linkFile, STREAM_URL_STAT_LINK);

                    expect($statResult)->toBeArray()
                        ->and($lstatResult)->toBeArray()
                    ;

                    $ref = new ReflectionClass(StreamWrapper::class);
                    $prop = $ref->getProperty('statCache');
                    $cache = $prop->getValue();

                    $normLink = str_replace('\\', '/', $linkFile);
                    expect($cache)->toHaveKey($normLink . ':stat')
                        ->and($cache)->toHaveKey($normLink . ':lstat')
                    ;
                } finally {
                    @unlink($linkFile);
                }
            }

            if (file_exists($targetFile)) {
                @unlink($targetFile);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        });

        test('bypasses positive stat caching for non-PHP dynamic test assets (.txt, .log)', function () {
            $wrapper = new StreamWrapper();
            $tempDir = sys_get_temp_dir() . '/typephp_txt_test_' . uniqid();
            mkdir($tempDir, 0777, true);

            $txtFile = $tempDir . '/sample.txt';
            file_put_contents($txtFile, 'text content');

            try {
                $stat = $wrapper->url_stat($txtFile, 0);
                expect($stat)->toBeArray();

                $ref = new ReflectionClass(StreamWrapper::class);
                $prop = $ref->getProperty('statCache');
                $cache = $prop->getValue();

                $normTxt = str_replace('\\', '/', $txtFile);
                // Non-PHP, non-vendor files are not memoized in statCache
                expect($cache)->not()->toHaveKey($normTxt . ':stat');
            } finally {
                if (file_exists($txtFile)) {
                    @unlink($txtFile);
                }
                if (is_dir($tempDir)) {
                    @rmdir($tempDir);
                }
            }
        });

        test('caches negative misses strictly for immutable vendor paths', function () {
            $wrapper = new StreamWrapper();
            $projectRoot = str_replace('\\', '/', Config::getProjectRoot());
            $missingVendorFile = $projectRoot . '/vendor/non_existent_package/Missing.php';

            $miss1 = $wrapper->url_stat($missingVendorFile, 0);
            $miss2 = $wrapper->url_stat($missingVendorFile, 0);

            expect($miss1)->toBeFalse()
                ->and($miss2)->toBeFalse()
            ;

            $ref = new ReflectionClass(StreamWrapper::class);
            $negProp = $ref->getProperty('staticNegativeStatCache');
            $negCache = $negProp->getValue();

            expect($negCache)->toHaveKey($missingVendorFile);
        });

        test('never caches negative misses for application test files and temp directories', function () {
            $wrapper = new StreamWrapper();
            $projectRoot = str_replace('\\', '/', Config::getProjectRoot());
            $missingAppTempFile = $projectRoot . '/tests/Fixtures/tmp/session.tmp';

            $miss = $wrapper->url_stat($missingAppTempFile, 0);
            expect($miss)->toBeFalse();

            $ref = new ReflectionClass(StreamWrapper::class);
            $negProp = $ref->getProperty('staticNegativeStatCache');
            $negCache = $negProp->getValue();

            expect($negCache)->not()->toHaveKey($missingAppTempFile);
        });

        test('invalidates both :stat and :lstat cache keys upon file mutations (unlink, rmdir, rename, mkdir)', function () {
            $wrapper = new StreamWrapper();
            $tempDir = sys_get_temp_dir() . '/typephp_mutation_test_' . uniqid();
            $tempFile = $tempDir . '/test.php';

            $wrapper->mkdir($tempDir, 0777, STREAM_MKDIR_RECURSIVE);
            file_put_contents($tempFile, '<?php // test');

            $stat = $wrapper->url_stat($tempFile, 0);
            expect($stat)->toBeArray();

            $normFile = str_replace('\\', '/', $tempFile);

            $ref = new ReflectionClass(StreamWrapper::class);
            $prop = $ref->getProperty('statCache');

            expect($prop->getValue())->toHaveKey($normFile . ':stat');

            $wrapper->stream_metadata($tempFile, STREAM_META_TOUCH, [time(), time()]);
            expect($prop->getValue())->not()->toHaveKey($normFile . ':stat');

            $renamedFile = $tempDir . '/renamed.php';
            $wrapper->rename($tempFile, $renamedFile);

            $wrapper->unlink($renamedFile);
            $wrapper->rmdir($tempDir, 0);

            expect(true)->toBeTrue();
        });
    });

    describe('Native Filesystem Error Passthrough & Mutations', function () {
        test('rmdir on non-empty directory emits native Directory not empty warning instead of internal error', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_rmdir_passthru_' . uniqid();
            mkdir($tempDir . '/sub', 0777, true);
            file_put_contents($tempDir . '/sub/file.txt', 'content');

            $caughtWarning = '';
            set_error_handler(function (int $errno, string $errstr) use (&$caughtWarning) {
                $caughtWarning .= $errstr;

                return true;
            });

            try {
                $result = rmdir($tempDir . '/sub');
            } finally {
                restore_error_handler();
            }

            expect($result)->toBeFalse()
                ->and(strtolower($caughtWarning))->toContain('directory not empty')
                ->and(strtolower($caughtWarning))->not()->toContain('internal error')
            ;

            @unlink($tempDir . '/sub/file.txt');
            @rmdir($tempDir . '/sub');
            @rmdir($tempDir);
        });

        test('unlink on non-existent file returns false and emits standard warning without internal error', function () {
            $missingFile = sys_get_temp_dir() . '/missing_file_' . uniqid() . '.txt';

            $caughtWarning = '';
            set_error_handler(function (int $errno, string $errstr) use (&$caughtWarning) {
                $caughtWarning .= $errstr;

                return true;
            });

            try {
                $result = unlink($missingFile);
            } finally {
                restore_error_handler();
            }

            expect($result)->toBeFalse()
                ->and(strtolower($caughtWarning))->toContain('no such file or directory')
                ->and(strtolower($caughtWarning))->not()->toContain('internal error')
            ;
        });
    });

    describe('stream_open() & Context Support', function () {
        test('bypasses AST transformation on non-PHP files', function () {
            $wrapper = new StreamWrapper();
            $openedPath = null;
            $jsonFile = \dirname(__DIR__, 3) . '/composer.json';

            $success = $wrapper->stream_open($jsonFile, 'r', 0, $openedPath);
            expect($success)->toBeTrue();

            $content = $wrapper->stream_read(1000);
            expect($content)->toContain('"name": "typephp/typephp"');
            $wrapper->stream_close();
        });

        test('supports stream context options when opening direct handles', function () {
            $wrapper = new StreamWrapper();
            $context = stream_context_create([
                'file' => [
                    'ignore_errors' => true,
                ],
            ]);
            $wrapper->context = $context;

            $openedPath = null;
            $target = __FILE__;

            $success = $wrapper->stream_open($target, 'r', 0, $openedPath);
            expect($success)->toBeTrue();
            $wrapper->stream_close();
        });

        test('bypasses AST transformation for unwhitelisted vendor files', function () {
            try {
                Config::set([
                    'include' => ['src/**'],
                    'exclude' => ['vendor/**'],
                ]);

                $projectRoot = str_replace('\\', '/', Config::getProjectRoot());
                $vendorFile = $projectRoot . '/vendor/composer/autoload_real.php';

                if (file_exists($vendorFile)) {
                    $wrapper = new StreamWrapper();
                    $openedPath = null;
                    $success = $wrapper->stream_open($vendorFile, 'r', 0, $openedPath);

                    expect($success)->toBeTrue();
                    $wrapper->stream_close();
                }
            } finally {
                Config::reset();
            }
        });

        test('transforms whitelisted vendor files when explicitly included in config', function () {
            try {
                Config::set([
                    'include' => [
                        'src/**',
                        'vendor/monolog/monolog/src/Monolog/Logger.php',
                    ],
                    'exclude' => [
                        'vendor/**',
                    ],
                ]);

                $projectRoot = str_replace('\\', '/', Config::getProjectRoot());
                $whitelistedVendorFile = $projectRoot . '/vendor/monolog/monolog/src/Monolog/Logger.php';

                expect(FileFilter::isFileExcluded($whitelistedVendorFile))->toBeFalse();
            } finally {
                Config::reset();
            }
        });
    });

    describe('Vendor Subpackage Isolation', function () {
        test('strictly isolates vendor files with nested src directories when application includes specific src subpackages', function () {
            try {
                Config::set([
                    'include' => [
                        'src/**',
                        'src/Core/**',
                        'src/Storefront/**',
                        'src/Administration/**',
                    ],
                    'exclude' => [
                        'vendor/**',
                        'storage/**',
                        'var/**',
                        'cache/**',
                    ],
                ]);

                $projectRoot = Config::getProjectRoot();

                $vendorFile = str_replace('\\', '/', $projectRoot . '/vendor/doctrine/dbal/src/Core/Table.php');
                $appFile = str_replace('\\', '/', $projectRoot . '/src/Core/Framework/Util.php');

                expect(FileFilter::isFileExcluded($vendorFile))->toBeTrue()
                    ->and(FileFilter::isFileExcluded($appFile))->toBeFalse()
                ;
            } finally {
                Config::reset();
            }
        });
    });

    describe('File Functions Non-Interference (STREAM_OPEN_FOR_INCLUDE)', function () {
        test('file_get_contents() returns raw source code without AST transformation', function () {
            StreamWrapper::register();

            $sysTemp = realpath(sys_get_temp_dir());
            $baseTemp = str_replace('\\', '/', $sysTemp !== false ? $sysTemp : sys_get_temp_dir());
            $tempDir = $baseTemp . '/typephp_raw_read_' . uniqid();
            mkdir($tempDir, 0777, true);

            $canonicalDir = str_replace('\\', '/', realpath($tempDir) ?: $tempDir);
            $testFile = $canonicalDir . '/SampleService.php';

            $rawSource = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Test;

/**
 * @param positive-int $id
 * @return non-empty-string
 */
function sampleAction(int $id): string
{
    return "id_{$id}";
}
PHP;
            file_put_contents($testFile, $rawSource);

            try {
                Config::set([
                    'include' => [
                        $canonicalDir . '/**',
                    ],
                    'exclude' => [
                        'vendor/**',
                    ],
                ]);

                $readSource = file_get_contents($testFile);

                expect($readSource)->toBe($rawSource)
                    ->and($readSource)->not()->toContain('RuntimeTypeChecker::setupScope')
                    ->and($readSource)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ;

                $fp = fopen($testFile, 'r');
                expect($fp)->not()->toBeFalse();
                $streamContent = fread($fp, 5000);
                fclose($fp);

                expect($streamContent)->toBe($rawSource)
                    ->and($streamContent)->not()->toContain('RuntimeTypeChecker::setupScope')
                ;

                require $testFile;

                expect(\App\Test\sampleAction(42))->toBe('id_42');

                expect(fn () => \App\Test\sampleAction(-5))
                    ->toThrow(TypeError::class, 'positive-int')
                ;
            } finally {
                if (file_exists($testFile)) {
                    @unlink($testFile);
                }
                if (is_dir($tempDir)) {
                    @rmdir($tempDir);
                }
            }
        });

        test('file_put_contents() writes data directly without stream interception', function () {
            StreamWrapper::register();

            $sysTemp = realpath(sys_get_temp_dir());
            $baseTemp = str_replace('\\', '/', $sysTemp !== false ? $sysTemp : sys_get_temp_dir());
            $tempDir = $baseTemp . '/typephp_raw_write_' . uniqid();
            mkdir($tempDir, 0777, true);

            $testFile = $tempDir . '/data_write.txt';
            $payload = 'raw_unmodified_payload_12345';

            try {
                $bytesWritten = file_put_contents($testFile, $payload);

                expect($bytesWritten)->toBe(\strlen($payload))
                    ->and(file_get_contents($testFile))->toBe($payload)
                ;
            } finally {
                if (file_exists($testFile)) {
                    @unlink($testFile);
                }
                if (is_dir($tempDir)) {
                    @rmdir($tempDir);
                }
            }
        });

        test('stream_open options flag differentiates include (128) from normal read (0)', function () {
            StreamWrapper::register();

            $sysTemp = realpath(sys_get_temp_dir());
            $baseTemp = str_replace('\\', '/', $sysTemp !== false ? $sysTemp : sys_get_temp_dir());
            $tempDir = $baseTemp . '/typephp_stream_opt_' . uniqid();
            mkdir($tempDir, 0777, true);

            $canonicalDir = str_replace('\\', '/', realpath($tempDir) ?: $tempDir);
            $testFile = $canonicalDir . '/DedicatedStreamTest.php';

            $rawSource = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\StreamTest;

/**
 * @param positive-int $id
 */
function dedicatedStreamAction(int $id): int
{
    return $id;
}
PHP;
            file_put_contents($testFile, $rawSource);

            try {
                Config::set([
                    'include' => [
                        $canonicalDir . '/**',
                    ],
                    'exclude' => [
                        'vendor/**',
                    ],
                ]);

                $wrapper = new StreamWrapper();
                $openedPath = null;

                $wrapper->stream_open($testFile, 'r', 0, $openedPath);
                $rawContent = $wrapper->stream_read(5000);
                $wrapper->stream_close();

                expect($rawContent)->not()->toContain('RuntimeTypeChecker::setupScope')
                    ->and($rawContent)->toBe($rawSource)
                ;

                $wrapper->stream_open($testFile, 'r', StreamWrapper::STREAM_OPEN_FOR_INCLUDE, $openedPath);
                $transformedContent = $wrapper->stream_read(5000);
                $wrapper->stream_close();

                expect($transformedContent)->toContain('RuntimeTypeChecker::setupScope');
            } finally {
                if (file_exists($testFile)) {
                    @unlink($testFile);
                }
                if (is_dir($tempDir)) {
                    @rmdir($tempDir);
                }
            }
        });
    });
});
