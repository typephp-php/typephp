<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Extension\ExtensionInterface;
use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Liskov\AppChildService;
use TypePHP\Tests\Fixtures\Liskov\SimulatedVendorParent;
use TypePHP\Tests\Fixtures\Services\ChildMagicMethodService;
use TypePHP\Tests\Fixtures\Services\HelperService;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;

describe('TypePHP Stub System Runtime Overrides & Edge Cases', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    test('overrides buggy vendor class DocBlock with a user stub file', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_stub_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stubPath = $tempDir . '/SimulatedVendorParent.stub';
        $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Liskov;

class SimulatedVendorParent
{
    /**
     * Corrected DocBlock from Stub!
     * @param positive-int $code
     */
    public function execute(int $code): bool
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

            $vendor = new SimulatedVendorParent();

            expect($vendor->execute(100))->toBeTrue();
            expect(fn () => $vendor->execute(-50))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            if (file_exists($stubPath)) {
                @unlink($stubPath);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    });

    test('child class inherits stubbed contracts across LSP inheritance', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_stub_lsp_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stubPath = $tempDir . '/SimulatedVendorParent.stub';
        $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Liskov;

class SimulatedVendorParent
{
    /**
     * @param positive-int $code
     */
    public function execute(int $code): bool
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

            $child = new AppChildService();

            expect($child->execute(42))->toBeTrue();

            expect(fn () => $child->execute(-10))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            if (file_exists($stubPath)) {
                @unlink($stubPath);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    });

    test('enforces return type contracts declared in a stub file', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_stub_return_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stubPath = $tempDir . '/HelperService.stub.php';
        $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Services;

class HelperService
{
    /**
     * @param int $id
     * @return non-empty-string
     */
    public function formatUser(int $id): string
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

            $helper = new HelperService();

            expect($helper->formatUser(10))->toBe('user_10');
            expect(fn () => $helper->formatUser(0))
                ->toThrow(TypeError::class, 'Return value must be of type non-empty-string')
            ;
        } finally {
            if (file_exists($stubPath)) {
                @unlink($stubPath);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    });

    test('resolves class-level @phpstan-type aliases defined in stub files', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_stub_alias_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stubPath = $tempDir . '/SimulatedVendorParent.stubs';
        $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Liskov;

/**
 * @phpstan-type CodeRange int<100, 500>
 */
class SimulatedVendorParent
{
    /**
     * @param CodeRange $code
     */
    public function execute(int $code): bool
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

            $vendor = new SimulatedVendorParent();

            expect($vendor->execute(200))->toBeTrue();

            expect(fn () => $vendor->execute(50))
                ->toThrow(TypeError::class, 'Argument $code')
            ;
        } finally {
            if (file_exists($stubPath)) {
                @unlink($stubPath);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    });

    test('overrides property @var contracts using stub files', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_stub_prop_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stubPath = $tempDir . '/ConfiguredProperty.stub';
        $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Types;

class ConfiguredProperty
{
    /**
     * @var non-empty-string
     */
    public static mixed $staticTitle;
}
PHP;
        file_put_contents($stubPath, $stubContent);

        try {
            Config::set([
                'stubs' => [
                    str_replace('\\', '/', $tempDir) . '/**',
                ],
            ]);

            ConfiguredProperty::assignStaticTitle('New Title');
            expect(ConfiguredProperty::$staticTitle)->toBe('New Title');

            // Setting empty string violates the stub's @var non-empty-string!
            expect(fn () => ConfiguredProperty::assignStaticTitle(''))
                ->toThrow(TypeError::class, 'must be of type non-empty-string')
            ;
        } finally {
            if (file_exists($stubPath)) {
                @unlink($stubPath);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    });

    test('overrides dynamic magic methods (@method) using stub files', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_stub_method_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stubPath = $tempDir . '/ChildMagicMethodService.stub';
        $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Services;

/**
 * @method positive-int calculateScore(positive-int $baseScore, non-empty-string $category)
 */
class ChildMagicMethodService
{
}
PHP;
        file_put_contents($stubPath, $stubContent);

        try {
            Config::set([
                'stubs' => [
                    str_replace('\\', '/', $tempDir) . '/**',
                ],
            ]);

            $service = new ChildMagicMethodService();

            expect($service->calculateScore(100, 'sports'))->toBe(100);

            // -50 violates stub's @param positive-int $baseScore
            expect(fn () => $service->calculateScore(-50, 'sports'))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            // Empty string violates stub's @param non-empty-string $category
            expect(fn () => $service->calculateScore(100, ''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        } finally {
            if (file_exists($stubPath)) {
                @unlink($stubPath);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }
    });

    test('loads stub files registered through third-party ExtensionInterface extensions', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_ext_stub_' . uniqid();
        mkdir($tempDir, 0777, true);

        $stubPath = $tempDir . '/VendorExtensionStub.custom';
        $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Liskov;

class SimulatedVendorParent
{
    /**
     * @param positive-int $code
     */
    public function execute(int $code): bool
    {
    }
}
PHP;
        file_put_contents($stubPath, $stubContent);

        $extensionClass = new class () implements ExtensionInterface {
            public static string $stubFilePath = '';

            public function getConfig(): array
            {
                return [
                    'stubs' => [self::$stubFilePath],
                ];
            }
        };
        $extensionClass::$stubFilePath = $stubPath;

        try {
            Config::set([
                'extensions' => [
                    $extensionClass::class,
                ],
            ]);

            $vendor = new SimulatedVendorParent();

            expect($vendor->execute(100))->toBeTrue();

            expect(fn () => $vendor->execute(-50))
                ->toThrow(TypeError::class, 'positive-int')
            ;
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
