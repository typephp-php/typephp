<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Util;

use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\IgnoreManager;

/**
 * Fixture: Method-level ignore caller
 */
class IgnoredMethodCallerFixture
{
    /**
     * @typephp-ignore
     */
    public static function executeIgnored(): bool
    {
        return IgnoreManager::isCallerIgnored();
    }

    /**
     * @typephp-disable
     */
    public static function executeDisabled(): bool
    {
        return IgnoreManager::isCallerIgnored();
    }

    public static function executeNormal(): bool
    {
        return IgnoreManager::isCallerIgnored();
    }
}

/**
 * Fixture: Class-level ignore caller
 *
 * @typephp-ignore
 */
class IgnoredClassCallerFixture
{
    public static function executeClassIgnored(): bool
    {
        return IgnoreManager::isCallerIgnored();
    }
}

/**
 * Fixture: Standard, un-annotated caller
 */
class NormalCallerFixture
{
    public static function execute(): bool
    {
        return IgnoreManager::isCallerIgnored();
    }
}

/**
 * Fixture: Dedicated caller for stub-based ignore testing
 */
class StubIgnoredCallerFixture
{
    public static function execute(): bool
    {
        return IgnoreManager::isCallerIgnored();
    }
}

/**
 * Standalone function with ignore tag
 *
 * @typephp-ignore
 */
function testIgnoredStandaloneCaller(): bool
{
    return IgnoreManager::isCallerIgnored();
}

/**
 * Standalone normal function without ignore tag
 */
function testNormalStandaloneCaller(): bool
{
    return IgnoreManager::isCallerIgnored();
}

describe('IgnoreManager Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        IgnoreManager::reset();
    });

    afterEach(function () {
        Config::reset();
        IgnoreManager::reset();
    });

    describe('File Registry (registerIgnoredFile & isFileIgnored)', function () {
        test('registers and identifies ignored file paths in memory with forward slash normalization', function () {
            $path = '/var/www/app/IgnoredFile.php';
            $windowsPath = 'C:\\project\\app\\Services\\IgnoredService.php';

            expect(IgnoreManager::isFileIgnored($path))->toBeFalse();

            IgnoreManager::registerIgnoredFile($path);
            IgnoreManager::registerIgnoredFile($windowsPath);

            expect(IgnoreManager::isFileIgnored($path))->toBeTrue()
                ->and(IgnoreManager::isFileIgnored('C:/project/app/Services/IgnoredService.php'))->toBeTrue()
                ->and(IgnoreManager::isFileIgnored($windowsPath))->toBeTrue()
                ->and(IgnoreManager::isFileIgnored('/var/www/app/OtherFile.php'))->toBeFalse()
            ;
        });

        test('handles empty file paths gracefully', function () {
            expect(IgnoreManager::isFileIgnored(''))->toBeFalse();

            IgnoreManager::registerIgnoredFile('');
            expect(IgnoreManager::isFileIgnored(''))->toBeFalse();
        });

        test('clears file registry on reset', function () {
            $path = '/var/www/app/Test.php';
            IgnoreManager::registerIgnoredFile($path);
            expect(IgnoreManager::isFileIgnored($path))->toBeTrue();

            IgnoreManager::reset();
            expect(IgnoreManager::isFileIgnored($path))->toBeFalse();
        });
    });

    describe('Caller Method & Class Ignore Detection (isCallerIgnored)', function () {
        test('identifies calling method marked with @typephp-ignore', function () {
            expect(IgnoredMethodCallerFixture::executeIgnored())->toBeTrue();
        });

        test('identifies calling method marked with @typephp-disable alias', function () {
            expect(IgnoredMethodCallerFixture::executeDisabled())->toBeTrue();
        });

        test('identifies calling method when entire declaring class has @typephp-ignore', function () {
            expect(IgnoredClassCallerFixture::executeClassIgnored())->toBeTrue();
        });

        test('returns false for un-annotated normal caller methods', function () {
            expect(IgnoredMethodCallerFixture::executeNormal())->toBeFalse()
                ->and(NormalCallerFixture::execute())->toBeFalse()
            ;
        });

        test('identifies standalone functions marked with @typephp-ignore', function () {
            expect(testIgnoredStandaloneCaller())->toBeTrue()
                ->and(testNormalStandaloneCaller())->toBeFalse()
            ;
        });

        test('supports direct explicit caller overrides', function () {
            expect(IgnoreManager::isCallerIgnored(IgnoredMethodCallerFixture::class, 'executeIgnored'))->toBeTrue()
                ->and(IgnoreManager::isCallerIgnored(NormalCallerFixture::class, 'execute'))->toBeFalse()
                ->and(IgnoreManager::isCallerIgnored(null, 'TypePHP\Tests\Internal\Util\testIgnoredStandaloneCaller'))->toBeTrue()
            ;
        });
    });

    describe('Stub-Based Caller Ignore Detection', function () {
        beforeEach(function () {
            Config::reset();
            IgnoreManager::reset();
        });

        afterEach(function () {
            Config::reset();
            IgnoreManager::reset();
        });

        test('identifies caller methods ignored via external stub files', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_ignore_stub_' . uniqid();
            mkdir($tempDir, 0777, true);

            $stubPath = $tempDir . '/StubIgnoredCallerFixture.stub';
            $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Internal\Util;

class StubIgnoredCallerFixture
{
    /**
     * @typephp-ignore
     */
    public static function execute(): bool
    {
    }
}
PHP;
            file_put_contents($stubPath, $stubContent);

            try {
                Config::set([
                    'stubs' => [
                        str_replace('\\', '/', $tempDir) . '/**',
                    ],
                ]);

                expect(StubIgnoredCallerFixture::execute())->toBeTrue();
            } finally {
                if (file_exists($stubPath)) {
                    @unlink($stubPath);
                }
                if (is_dir($tempDir)) {
                    @rmdir($tempDir);
                }
            }
        });
    });

    describe('Config Override (respect_ignore_tags => false)', function () {
        test('bypasses ignore tags completely when respect_ignore_tags is disabled in config', function () {
            try {
                Config::set(['respect_ignore_tags' => false]);

                expect(IgnoredMethodCallerFixture::executeIgnored())->toBeFalse()
                    ->and(IgnoredClassCallerFixture::executeClassIgnored())->toBeFalse()
                    ->and(testIgnoredStandaloneCaller())->toBeFalse()
                ;
            } finally {
                Config::reset();
            }
        });
    });

    describe('In-Memory Decision Caching ($O(1) Memoization)', function () {
        test('retrieves subsequent caller decisions directly from cache', function () {
            expect(IgnoredMethodCallerFixture::executeIgnored())->toBeTrue();
            expect(NormalCallerFixture::execute())->toBeFalse();

            expect(IgnoredMethodCallerFixture::executeIgnored())->toBeTrue();
            expect(NormalCallerFixture::execute())->toBeFalse();
        });
    });
});