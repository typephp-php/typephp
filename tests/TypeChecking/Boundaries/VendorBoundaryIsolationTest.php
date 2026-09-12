<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
use TypePHP\Internal\Util\Config;

describe('Caller-Aware Vendor Boundary Isolation', function () {
    beforeEach(function () {
        Config::reset();
        CallerBoundaryResolver::reset();
        StreamWrapper::reset();
    });

    afterEach(function () {
        Config::reset();
        CallerBoundaryResolver::reset();
        StreamWrapper::reset();
    });

    test('enforces types when application code calls whitelisted vendor methods', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_vendor_test_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;

        $vendorDir = $tempDir . '/vendor/acme/sample-lib/src';
        mkdir($vendorDir, 0777, true);

        $vendorFile = $vendorDir . '/WhitelistedService.php';
        $vendorSource = <<<'PHP'
<?php

namespace Simulated\Vendor;

class WhitelistedService
{
    /**
     * @param positive-int $code
     * @return positive-int
     */
    public function processCode(int $code): int
    {
        return $code;
    }
}
PHP;
        file_put_contents($vendorFile, $vendorSource);

        try {
            $normTempDir = str_replace('\\', '/', $tempDir);

            Config::set([
                'vendor_boundary_only' => true,
                'include' => [
                    'tests/**',
                    $normTempDir . '/vendor/acme/sample-lib/**',
                ],
                'exclude' => [
                    'vendor/**',
                ],
            ]);

            StreamWrapper::register();

            require_once $vendorFile;

            $service = new \Simulated\Vendor\WhitelistedService();

            expect($service->processCode(100))->toBe(100);
            expect(fn () => $service->processCode(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            if (file_exists($vendorFile)) {
                @unlink($vendorFile);
            }
            if (is_dir($vendorDir)) {
                @rmdir($vendorDir);
                @rmdir($tempDir . '/vendor/acme/sample-lib');
                @rmdir($tempDir . '/vendor/acme');
                @rmdir($tempDir . '/vendor');
                @rmdir($tempDir);
            }
        }
    });

    test('bypasses type enforcement when call originates from an excluded vendor package', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_boundary_bypass_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;

        $whitelistedDir = $tempDir . '/vendor/acme/collection/src';
        $excludedDir = $tempDir . '/vendor/third-party/caller/src';

        mkdir($whitelistedDir, 0777, true);
        mkdir($excludedDir, 0777, true);

        $whitelistedFile = $whitelistedDir . '/WhitelistedCollection.php';
        $excludedFile = $excludedDir . '/ExcludedVendorCaller.php';

        $whitelistedSource = <<<'PHP'
<?php

namespace Simulated\Collections;

class WhitelistedCollection
{
    /**
     * @param positive-int $id
     * @return positive-int
     */
    public function find(int $id): int
    {
        return $id;
    }
}
PHP;

        $excludedSource = <<<'PHP'
<?php

namespace Simulated\ThirdParty;

use Simulated\Collections\WhitelistedCollection;

class ExcludedVendorCaller
{
    public function makeInvalidVendorCall(WhitelistedCollection $collection): int
    {
        // Vendor calling vendor with invalid -999!
        return $collection->find(-999);
    }
}
PHP;

        file_put_contents($whitelistedFile, $whitelistedSource);
        file_put_contents($excludedFile, $excludedSource);

        try {
            $normTempDir = str_replace('\\', '/', $tempDir);

            Config::set([
                'vendor_boundary_only' => true,
                'include' => [
                    'tests/**',
                    $normTempDir . '/vendor/acme/collection/**', 
                ],
                'exclude' => [
                    $normTempDir . '/vendor/third-party/**', 
                ],
            ]);

            StreamWrapper::register();

            require_once $whitelistedFile;
            require_once $excludedFile;

            $collection = new \Simulated\Collections\WhitelistedCollection();
            $caller = new \Simulated\ThirdParty\ExcludedVendorCaller();
            $result = $caller->makeInvalidVendorCall($collection);
            expect($result)->toBe(-999);
        } finally {
            @unlink($whitelistedFile);
            @unlink($excludedFile);
            @rmdir($whitelistedDir);
            @rmdir($excludedDir);
            @rmdir($tempDir . '/vendor/acme/collection');
            @rmdir($tempDir . '/vendor/acme');
            @rmdir($tempDir . '/vendor/third-party/caller');
            @rmdir($tempDir . '/vendor/third-party');
            @rmdir($tempDir . '/vendor');
            @rmdir($tempDir);
        }
    });

    test('bypasses type enforcement when a whitelisted vendor class performs an internal self-call', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_self_call_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;

        $vendorDir = $tempDir . '/vendor/acme/morph/src';
        mkdir($vendorDir, 0777, true);

        $vendorFile = $vendorDir . '/MorphService.php';
        $vendorSource = <<<'PHP'
<?php

namespace Simulated\Morph;

class MorphService
{
    /**
     * @param positive-int $count
     * @return positive-int
     */
    public function setStrictCount(int $count): int
    {
        return $count;
    }

    public function internalSelfMethod(): int
    {
        // Vendor class calling another method on $this with invalid type
        return $this->setStrictCount(-42);
    }
}
PHP;
        file_put_contents($vendorFile, $vendorSource);

        try {
            $normTempDir = str_replace('\\', '/', $tempDir);

            Config::set([
                'vendor_boundary_only' => true,
                'include' => [
                    'tests/**',
                    $normTempDir . '/vendor/acme/morph/**',
                ],
                'exclude' => [
                    'vendor/**',
                ],
            ]);

            StreamWrapper::register();

            require_once $vendorFile;

            $morph = new \Simulated\Morph\MorphService();

            expect($morph->internalSelfMethod())->toBe(-42);
            expect(fn () => $morph->setStrictCount(-42))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            @unlink($vendorFile);
            @rmdir($vendorDir);
            @rmdir($tempDir . '/vendor/acme/morph');
            @rmdir($tempDir . '/vendor/acme');
            @rmdir($tempDir . '/vendor');
            @rmdir($tempDir);
        }
    });

    test('strictly enforces types across all callers when vendor_boundary_only is false', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_strict_all_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;

        $whitelistedDir = $tempDir . '/vendor/acme/strict/src';
        $excludedDir = $tempDir . '/vendor/third-party/strict-caller/src';

        mkdir($whitelistedDir, 0777, true);
        mkdir($excludedDir, 0777, true);

        $whitelistedFile = $whitelistedDir . '/StrictService.php';
        $excludedFile = $excludedDir . '/StrictCaller.php';

        $whitelistedSource = <<<'PHP'
<?php

namespace Simulated\Strict;

class StrictService
{
    /**
     * @param positive-int $id
     * @return positive-int
     */
    public function requirePositive(int $id): int
    {
        return $id;
    }
}
PHP;

        $excludedSource = <<<'PHP'
<?php

namespace Simulated\StrictCaller;

use Simulated\Strict\StrictService;

class StrictCaller
{
    public function execute(StrictService $service): int
    {
        return $service->requirePositive(-100);
    }
}
PHP;

        file_put_contents($whitelistedFile, $whitelistedSource);
        file_put_contents($excludedFile, $excludedSource);

        try {
            $normTempDir = str_replace('\\', '/', $tempDir);

            Config::set([
                'vendor_boundary_only' => false, 
                'include' => [
                    'tests/**',
                    $normTempDir . '/vendor/acme/strict/**',
                ],
                'exclude' => [
                    $normTempDir . '/vendor/third-party/**',
                ],
            ]);

            StreamWrapper::register();

            require_once $whitelistedFile;
            require_once $excludedFile;

            $service = new \Simulated\Strict\StrictService();
            $caller = new \Simulated\StrictCaller\StrictCaller();

            expect(fn () => $caller->execute($service))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            @unlink($whitelistedFile);
            @unlink($excludedFile);
            @rmdir($whitelistedDir);
            @rmdir($excludedDir);
            @rmdir($tempDir . '/vendor/acme/strict');
            @rmdir($tempDir . '/vendor/acme');
            @rmdir($tempDir . '/vendor/third-party/strict-caller');
            @rmdir($tempDir . '/vendor/third-party');
            @rmdir($tempDir . '/vendor');
            @rmdir($tempDir);
        }
    });
});