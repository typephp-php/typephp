<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Resolver;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
use TypePHP\Internal\Util\Config;

describe('CallerBoundaryResolver Inherited Vendor Methods & Properties (Red Phase)', function () {
    beforeEach(function () {
        Config::reset();
        StreamWrapper::reset();
        CallerBoundaryResolver::reset();
    });

    afterEach(function () {
        Config::reset();
        StreamWrapper::reset();
        CallerBoundaryResolver::reset();
    });

    test('bypasses vendor-internal static/instance property accesses and inherited methods on app subclasses while still enforcing app boundaries', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_vendor_inherit_' . uniqid();
        $vendorDir = $baseTemp . '/vendor/acme/eloquent';
        $appDir = $baseTemp . '/app';
        mkdir($vendorDir, 0777, true);
        mkdir($appDir, 0777, true);

        $realBase = str_replace('\\', '/', realpath($baseTemp) ?: $baseTemp);
        $vendorModelFile = $realBase . '/vendor/acme/eloquent/VendorBaseModel.php';
        $vendorCallerFile = $realBase . '/vendor/acme/eloquent/VendorCallerService.php';
        $appModelFile = $realBase . '/app/InheritedAppUser.php';
        $appServiceFile = $realBase . '/app/AppAuthService.php';

        file_put_contents($vendorModelFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\VendorEloquent;

class VendorBaseModel
{
    /**
     * @var array<class-string, mixed>
     */
    protected static array $classAttributes = [];

    /**
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    public static function resolveClassAttribute(string $attributeClass, string $property): mixed
    {
        $cacheKey = static::class . '@' . $attributeClass . '@' . $property;

        if (array_key_exists($cacheKey, static::$classAttributes)) {
            return static::$classAttributes[$cacheKey];
        }

        return static::$classAttributes[$cacheKey] = 'cached_attr';
    }

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }

    public function setRawAttr(string $key, mixed $val): void
    {
        $this->attributes[$key] = $val;
    }
}
PHP);

        file_put_contents($vendorCallerFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace Acme\VendorEloquent;

class VendorCallerService
{
    public static function bootAndResolve(string $modelClass): mixed
    {
        $attr = $modelClass::resolveClassAttribute('Acme\\Attr\\Format', 'created_at');
        $instance = new $modelClass();
        $instance->setRawAttr('is_active', true);

        // Vendor-internal magic read of an app model property should bypass under vendor_boundary_only
        $active = $instance->is_active;

        return [$attr, $active];
    }
}
PHP);

        file_put_contents($appModelFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\VendorBoundaryDemo;

use Acme\VendorEloquent\VendorBaseModel;

/**
 * @property object $is_active
 */
class InheritedAppUser extends VendorBaseModel
{
    public static function writeBadClassAttributeFromApp(): void
    {
        static::$classAttributes['not-a-class-string'] = 'bad';
    }
}
PHP);

        file_put_contents($appServiceFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace App\VendorBoundaryDemo;

class AppAuthService
{
    public function readInvalidMagicProperty(InheritedAppUser $user): mixed
    {
        return $user->is_active;
    }
}
PHP);

        try {
            Config::set([
                'vendor_boundary_only' => true,
                'magic_properties' => ['write' => true, 'read' => true],
                'include' => [
                    $realBase . '/app/**',
                    $realBase . '/vendor/acme/eloquent/VendorBaseModel.php',
                ],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            require_once $vendorModelFile;
            require_once $vendorCallerFile;
            require_once $appModelFile;
            require_once $appServiceFile;

            $res = \Acme\VendorEloquent\VendorCallerService::bootAndResolve(\App\VendorBoundaryDemo\InheritedAppUser::class);
            expect($res)->toBe(['cached_attr', true]);

            $user = new \App\VendorBoundaryDemo\InheritedAppUser();
            $user->setRawAttr('is_active', true);
            $authService = new \App\VendorBoundaryDemo\AppAuthService();

            expect(fn () => $authService->readInvalidMagicProperty($user))
                ->toThrow(TypeError::class, 'Property App\VendorBoundaryDemo\InheritedAppUser::$is_active must be of type object')
            ;

            expect(fn () => \App\VendorBoundaryDemo\InheritedAppUser::writeBadClassAttributeFromApp())
                ->toThrow(TypeError::class, 'class-string')
            ;
        } finally {
            @unlink($vendorModelFile);
            @unlink($vendorCallerFile);
            @unlink($appModelFile);
            @unlink($appServiceFile);
            @rmdir($vendorDir);
            @rmdir($realBase . '/vendor/acme');
            @rmdir($realBase . '/vendor');
            @rmdir($appDir);
            @rmdir($realBase);
        }
    });
});
