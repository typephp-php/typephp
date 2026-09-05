<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StubManager;

describe('StubManager Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    test('indexes and extracts DocBlocks from stub files with any extension (.stub, .stub.php, .custom)', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_stubs_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stub1Path = $tempDir . '/VendorService.stub';
        $stub2Path = $tempDir . '/functions.custom';

        try {
            $stub1Content = <<<'PHP'
<?php

namespace Vendor\Acme;

/**
 * @template T of object
 * @phpstan-type LocalAlias array{id: positive-int}
 */
class VendorService
{
    /**
     * @var positive-int
     */
    public int $version;

    /**
     * @param positive-int $id
     * @param non-empty-string $token
     * @return non-empty-string
     */
    public function execute(int $id, string $token): string
    {
    }
}
PHP;
            file_put_contents($stub1Path, $stub1Content);

            $stub2Content = <<<'PHP'
<?php
namespace Vendor\Acme;

/**
 * @param positive-int $code
 */
function helperFunction(int $code): void
{
}
PHP;
            file_put_contents($stub2Path, $stub2Content);
            Config::set([
                'stubs' => [
                    str_replace('\\', '/', $tempDir) . '/**',
                ],
            ]);

            StubManager::init();

            expect(StubManager::hasClassStub('Vendor\Acme\VendorService'))->toBeTrue()
                ->and(StubManager::getClassDoc('Vendor\Acme\VendorService'))->toContain('@template T of object')
                ->and(StubManager::getClassDoc('Vendor\Acme\VendorService'))->toContain('@phpstan-type LocalAlias')
            ;

            expect(StubManager::hasMethodStub('Vendor\Acme\VendorService', 'execute'))->toBeTrue()
                ->and(StubManager::getMethodDoc('Vendor\Acme\VendorService', 'execute'))->toContain('@param positive-int $id')
                ->and(StubManager::getMethodDoc('Vendor\Acme\VendorService', 'execute'))->toContain('@return non-empty-string')
            ;

            expect(StubManager::hasPropertyStub('Vendor\Acme\VendorService', 'version'))->toBeTrue()
                ->and(StubManager::getPropertyDoc('Vendor\Acme\VendorService', 'version'))->toContain('@var positive-int')
            ;

            expect(StubManager::hasFunctionStub('Vendor\Acme\helperFunction'))->toBeTrue()
                ->and(StubManager::getFunctionDoc('Vendor\Acme\helperFunction'))->toContain('@param positive-int $code')
            ;

            expect(StubManager::getMethodDoc('Vendor\Acme\VendorService', 'nonExistentMethod'))->toBeNull();
            expect(StubManager::getClassDoc('NonExistentClass'))->toBeNull();
        } finally {
            if (file_exists($stub1Path)) {
                @unlink($stub1Path);
            }

            if (file_exists($stub2Path)) {
                @unlink($stub2Path);
            }

            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    });
});
