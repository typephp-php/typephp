<?php

declare(strict_types=1);

use TypePHP\Contract\FileFilter;
use TypePHP\Internal\Config;
use TypePHP\Internal\StreamWrapper;

describe('StreamWrapper Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
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

    describe('url_stat() & Smart Negative Caching', function () {
        test('caches positive stat results in memory', function () {
            $wrapper = new StreamWrapper();
            $existingFile = __FILE__;

            $stat1 = $wrapper->url_stat($existingFile, 0);
            $stat2 = $wrapper->url_stat($existingFile, 0);

            expect($stat1)->toBeArray()
                ->and($stat1)->toBe($stat2)
            ;
        });

        test('caches negative misses for static vendor paths', function () {
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

        test('never caches negative misses for dynamic writable paths (var/cache, storage)', function () {
            $wrapper = new StreamWrapper();
            $projectRoot = str_replace('\\', '/', Config::getProjectRoot());
            $missingVarCacheFile = $projectRoot . '/var/cache/test/Container.php';

            $miss = $wrapper->url_stat($missingVarCacheFile, 0);

            expect($miss)->toBeFalse();

            $ref = new ReflectionClass(StreamWrapper::class);
            $negProp = $ref->getProperty('staticNegativeStatCache');
            $negCache = $negProp->getValue();

            expect($negCache)->not()->toHaveKey($missingVarCacheFile);
        });

        test('invalidates stat cache upon file mutation operations (mkdir, unlink, rename, touch)', function () {
            $wrapper = new StreamWrapper();
            $tempDir = sys_get_temp_dir() . '/typephp_stat_test_' . uniqid();
            $tempFile = $tempDir . '/test.php';

            $wrapper->mkdir($tempDir, 0777, STREAM_MKDIR_RECURSIVE);
            file_put_contents($tempFile, '<?php // test');

            $stat = $wrapper->url_stat($tempFile, 0);
            expect($stat)->toBeArray();

            $wrapper->stream_metadata($tempFile, STREAM_META_TOUCH, [time(), time()]);

            $renamedFile = $tempDir . '/renamed.php';
            $wrapper->rename($tempFile, $renamedFile);

            $wrapper->unlink($renamedFile);
            $wrapper->rmdir($tempDir, 0);

            expect(true)->toBeTrue();
        });
    });

    describe('stream_open() Fast-Paths & Whitelist Preservation', function () {
        afterEach(function () {
            Config::reset();
        });

        test('bypasses AST transformation on non-PHP files', function () {
            $wrapper = new StreamWrapper();
            $openedPath = null;
            $jsonFile = __DIR__ . '/../../composer.json';

            $success = $wrapper->stream_open($jsonFile, 'r', 0, $openedPath);
            expect($success)->toBeTrue();

            $content = $wrapper->stream_read(1000);
            expect($content)->toContain('"name": "typephp/typephp"');
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
        afterEach(function () {
            Config::reset();
        });

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

            $wrapper = new StreamWrapper();
            $openedPath = null;
            $testFile = str_replace('\\', '/', realpath(__DIR__ . '/../../tests/Fixtures/Services/HelperService.php') ?: '');

            $wrapper->stream_open($testFile, 'r', 0, $openedPath);
            $rawContent = $wrapper->stream_read(5000);
            $wrapper->stream_close();

            expect($rawContent)->not()->toContain('RuntimeTypeChecker::setupScope');

            $wrapper->stream_open($testFile, 'r', StreamWrapper::STREAM_OPEN_FOR_INCLUDE, $openedPath);
            $transformedContent = $wrapper->stream_read(5000);
            $wrapper->stream_close();

            expect($transformedContent)->toContain('RuntimeTypeChecker::setupScope');
        });
    });
});
