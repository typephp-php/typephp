<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Io;

use ErrorException;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

describe('StreamWrapper Exception Safety', function () {
    beforeEach(function () {
        Config::reset();
        StreamWrapper::reset();
        StreamWrapper::register();
    });

    afterEach(function () {
        StreamWrapper::register();
        Config::reset();
        StreamWrapper::reset();
    });

    $withThrowingErrorHandler = static function (callable $action): void {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $action();
        } finally {
            restore_error_handler();
        }
    };

    test('keeps file:// wrapper registered when unlink() throws ErrorException via custom error handler', function () use ($withThrowingErrorHandler) {
        $missingFile = sys_get_temp_dir() . '/typephp_ex_unlink_' . uniqid() . '.tmp';

        expect(StreamWrapper::isRegistered())->toBeTrue();

        $caught = false;

        try {
            $withThrowingErrorHandler(static function () use ($missingFile): void {
                unlink($missingFile);
            });
        } catch (ErrorException) {
            $caught = true;
        }

        expect($caught)->toBeTrue()
            ->and(StreamWrapper::isRegistered())->toBeTrue()
        ;
    });

    test('keeps file:// wrapper registered when rmdir() throws ErrorException on non-empty directory', function () use ($withThrowingErrorHandler) {
        $tempDir = sys_get_temp_dir() . '/typephp_ex_rmdir_' . uniqid();
        mkdir($tempDir, 0777, true);
        $childFile = $tempDir . '/keep.txt';
        file_put_contents($childFile, 'data');

        try {
            $caught = false;

            try {
                $withThrowingErrorHandler(static function () use ($tempDir): void {
                    rmdir($tempDir);
                });
            } catch (ErrorException) {
                $caught = true;
            }

            expect($caught)->toBeTrue()
                ->and(StreamWrapper::isRegistered())->toBeTrue()
            ;
        } finally {
            StreamWrapper::register();
            @unlink($childFile);
            @rmdir($tempDir);
        }
    });

    test('keeps file:// wrapper registered when rename() throws ErrorException on missing source', function () use ($withThrowingErrorHandler) {
        $missingFrom = sys_get_temp_dir() . '/typephp_ex_rename_from_' . uniqid() . '.tmp';
        $destTo = sys_get_temp_dir() . '/typephp_ex_rename_to_' . uniqid() . '.tmp';

        $caught = false;

        try {
            $withThrowingErrorHandler(static function () use ($missingFrom, $destTo): void {
                rename($missingFrom, $destTo);
            });
        } catch (ErrorException) {
            $caught = true;
        }

        expect($caught)->toBeTrue()
            ->and(StreamWrapper::isRegistered())->toBeTrue()
        ;
    });

    test('keeps file:// wrapper registered when mkdir() throws ErrorException under naive error handler', function () use ($withThrowingErrorHandler) {
        $tempDir = sys_get_temp_dir() . '/typephp_ex_mkdir_' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            $caught = false;

            try {
                $withThrowingErrorHandler(static function () use ($tempDir): void {
                    mkdir($tempDir);
                });
            } catch (ErrorException) {
                $caught = true;
            }

            expect($caught)->toBeTrue()
                ->and(StreamWrapper::isRegistered())->toBeTrue()
            ;
        } finally {
            StreamWrapper::register();
            @rmdir($tempDir);
        }
    });

    test('keeps file:// wrapper registered when stream_open() throws during application file read', function () use ($withThrowingErrorHandler) {
        $baseTemp = sys_get_temp_dir() . '/typephp_ex_open_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
        $dirNamedPhp = $tempDir . '/broken.php';
        mkdir($dirNamedPhp, 0777, true);

        try {
            Config::set([
                'cache' => false,
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register(['cache' => false]);

            $caught = false;

            try {
                $withThrowingErrorHandler(static function () use ($dirNamedPhp): void {
                    include $dirNamedPhp;
                });
            } catch (ErrorException) {
                $caught = true;
            }

            expect($caught)->toBeTrue()
                ->and(StreamWrapper::isRegistered())->toBeTrue()
            ;
        } finally {
            StreamWrapper::register();
            @rmdir($dirNamedPhp);
            @rmdir($tempDir);
        }
    });

    test('continues instrumenting subsequent required files after a caught filesystem ErrorException', function () use ($withThrowingErrorHandler) {
        $baseTemp = sys_get_temp_dir() . '/typephp_ex_e2e_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
        $scriptPath = $tempDir . '/after_error_service.php';
        $missingFile = $tempDir . '/does_not_exist.tmp';

        file_put_contents($scriptPath, <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Io\ExceptionSafetyFixture;

/**
 * @param positive-int $id
 */
function acceptPositiveAfterError(int $id): int
{
    return $id;
}
PHP);

        try {
            Config::set([
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            try {
                $withThrowingErrorHandler(static function () use ($missingFile): void {
                    unlink($missingFile);
                });
            } catch (ErrorException) {
            }

            require_once $scriptPath;

            expect(\TypePHP\Tests\Internal\Io\ExceptionSafetyFixture\acceptPositiveAfterError(10))->toBe(10);

            expect(fn () => \TypePHP\Tests\Internal\Io\ExceptionSafetyFixture\acceptPositiveAfterError(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            StreamWrapper::register();
            @unlink($scriptPath);
            @rmdir($tempDir);
        }
    });
});
