<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use ReflectionClass;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\Config;

class Priority2ParentConstFixture
{
    public const PARENT_LIMIT = 500;

    protected const PROTECTED_LIMIT = 300;
}

class Priority2ChildConstFixture extends Priority2ParentConstFixture
{
    public const CHILD_LIMIT = 500;
}

/**
 * @template T
 */
class Priority2InnerBoxFixture
{
    /**
     * @param T $value
     */
    public function __construct(public mixed $value) {}
}

/**
 * @template T
 */
class Priority2OuterBoxFixture
{
    /**
     * @var Priority2InnerBoxFixture<T>
     */
    public Priority2InnerBoxFixture $inner;

    /**
     * @param T $value
     */
    public function __construct(mixed $value)
    {
        $this->inner = new Priority2InnerBoxFixture($value);
    }
}

describe('Priority 2 Bugs: Cache Isolation, AST Printer & Class Constants (Red Phase)', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Bug 3: TypePHPPrinter String Literal Preservation (#, //, and Spaces)', function () {
        beforeEach(function () {
            Config::set(['eliminate_statically_proven_checks' => false]);
        });

        test('preserves hex color string with # inside injected constant check without line drift', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class ColorConfig
{
    /**
     * @var '#ffffff'
     */
    public const COLOR = '#ffffff';
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_color_hash.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkConstant')
                ->and($transformed)->toContain("'\\'#ffffff\\''")
                ->and($transformed)->not()->toContain('/*ffffff')
                ->and(substr_count($transformed, "\n"))->toBe(substr_count($source, "\n"))
            ;
        });

        test('preserves URL with double slashes // inside injected constant check without line drift', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class ApiConfig
{
    /**
     * @var 'https://example.com/api'
     */
    public const API_URL = 'https://example.com/api';
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_url_slashes.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkConstant')
                ->and($transformed)->toContain("'\\'https://example.com/api\\''")
                ->and($transformed)->not()->toContain('https:/*')
                ->and(substr_count($transformed, "\n"))->toBe(substr_count($source, "\n"))
            ;
        });

        test('preserves multiple consecutive spaces in string literals inside injected statements', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class SpacedConfig
{
    /**
     * @var 'a   b'
     */
    public const SPACES = 'a   b';
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_spaces_preserved.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkConstant')
                ->and($transformed)->toContain("'\\'a   b\\''")
                ->and($transformed)->not()->toContain("'\\'a b\\''")
                ->and(substr_count($transformed, "\n"))->toBe(substr_count($source, "\n"))
            ;
        });

        test('executes injected checks with #, //, escaped quotes, spaces, and attributes without ParseError', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p2_printer_' . uniqid();
            mkdir($tempDir, 0777, true);
            $validFile = $tempDir . '/printer_valid.php';
            $invalidFile = $tempDir . '/printer_invalid.php';

            $validCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2\Printer;

use AllowDynamicProperties;

#[AllowDynamicProperties]
class PrinterValidFixture
{
    /** @var '#ff00aa' */
    public const HEX = '#ff00aa';

    /** @var 'https://example.com/v1   beta' */
    public const URL = 'https://example.com/v1   beta';

    /** @var "it's // #   ok" */
    public const ESCAPED = "it's // #   ok";

    public static function run(): string
    {
        /** @var '#112233' $color */
        $color = '#112233';

        /** @var 'https://a.test/x   y' $url */
        $url = 'https://a.test/x   y';

        return $color . '|' . $url;
    }
}
PHP;

            $invalidCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2\Printer;

class PrinterInvalidFixture
{
    /** @var 'https://example.com/api' */
    public const API_URL = 'http://example.com/api';
}
PHP;

            file_put_contents($validFile, $validCode);
            file_put_contents($invalidFile, $invalidCode);

            try {
                StreamWrapper::register();

                require $validFile;

                expect(\App\Priority2\Printer\PrinterValidFixture::run())->toBe('#112233|https://a.test/x   y')
                    ->and(fn() => require $invalidFile)->toThrow(TypeError::class)
                ;
            } finally {
                @unlink($validFile);
                @unlink($invalidFile);
                @rmdir($tempDir);
            }
        });
    });

    describe('Bug 4: TemplateManager::reset() Cache Clearing Omission', function () {
        test('clears compiledBindPlanBySigCache and propagatedPropTypeCache on reset', function () {
            $ref = new ReflectionClass(TemplateManager::class);

            $bindPlanProp = $ref->getProperty('compiledBindPlanBySigCache');
            $propTypeProp = $ref->getProperty('propagatedPropTypeCache');

            $bindPlanProp->setValue(null, ['DummyClass' => ['DummySig' => ['skip' => true]]]);
            $propTypeProp->setValue(null, ['DummyClass' => [0 => ['DummySig' => null]]]);

            expect($bindPlanProp->getValue())->not()->toBeEmpty()
                ->and($propTypeProp->getValue())->not()->toBeEmpty()
            ;

            TemplateManager::reset();

            expect($bindPlanProp->getValue())->toBeEmpty()
                ->and($propTypeProp->getValue())->toBeEmpty()
            ;
        });

        test('clears naturally populated generic plan and propagation caches via Config::reset()', function () {
            $outer = new Priority2OuterBoxFixture('hello');
            TemplateManager::bindInstance($outer, Priority2OuterBoxFixture::class . '<string>');

            $ref = new ReflectionClass(TemplateManager::class);
            $bindPlanProp = $ref->getProperty('compiledBindPlanBySigCache');
            $propTypeProp = $ref->getProperty('propagatedPropTypeCache');

            expect($bindPlanProp->getValue())->toHaveKey(Priority2OuterBoxFixture::class)
                ->and($propTypeProp->getValue())->toHaveKey(Priority2OuterBoxFixture::class)
            ;

            Config::reset();

            expect($bindPlanProp->getValue())->toBeEmpty()
                ->and($propTypeProp->getValue())->toBeEmpty()
            ;
        });
    });

    describe('Bug 5: Class Constants with self::, parent::, static::, Visibility, and Class-Level Aliases', function () {
        test('resolves self::CONSTANT in class constant check for valid and invalid values', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p2_const_' . uniqid();
            mkdir($tempDir, 0777, true);
            $validFile = $tempDir . '/self_const_valid.php';
            $invalidFile = $tempDir . '/self_const_invalid.php';

            $validCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

class SelfConstValidFixture
{
    public const BASE_CODE = 200;

    /**
     * @var self::BASE_CODE
     */
    public const STATUS_OK = 200;
}
PHP;

            $invalidCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

class SelfConstInvalidFixture
{
    public const BASE_CODE = 200;

    /**
     * @var self::BASE_CODE
     */
    public const STATUS_OK = 404;
}
PHP;

            file_put_contents($validFile, $validCode);
            file_put_contents($invalidFile, $invalidCode);

            try {
                StreamWrapper::register();

                expect(fn() => require $validFile)->not()->toThrow(TypeError::class)
                    ->and(fn() => require $invalidFile)->toThrow(TypeError::class, 'BASE_CODE')
                ;
            } finally {
                @unlink($validFile);
                @unlink($invalidFile);
                @rmdir($tempDir);
            }
        });

        test('resolves non-public self::PRIVATE_BASE and static::CONSTANT in class constant checks', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p2_self_vis_' . uniqid();
            mkdir($tempDir, 0777, true);
            $validFile = $tempDir . '/self_vis_valid.php';
            $invalidFile = $tempDir . '/self_vis_invalid.php';

            $validCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

class SelfPrivateAndStaticValidFixture
{
    private const PRIVATE_BASE = 201;

    /**
     * @var self::PRIVATE_BASE
     */
    public const CREATED = 201;

    /**
     * @var static::CREATED
     */
    protected const ALIAS_CREATED = 201;
}
PHP;

            $invalidCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

class SelfPrivateInvalidFixture
{
    private const PRIVATE_BASE = 201;

    /**
     * @var self::PRIVATE_BASE
     */
    public const CREATED = 500;
}
PHP;

            file_put_contents($validFile, $validCode);
            file_put_contents($invalidFile, $invalidCode);

            try {
                StreamWrapper::register();

                expect(fn() => require $validFile)->not()->toThrow(TypeError::class)
                    ->and(fn() => require $invalidFile)->toThrow(TypeError::class, 'PRIVATE_BASE')
                ;
            } finally {
                @unlink($validFile);
                @unlink($invalidFile);
                @rmdir($tempDir);
            }
        });

        test('resolves parent::CONSTANT directly via RuntimeTypeChecker without swallowing Error', function () {
            $valid = RuntimeTypeChecker::checkClassConstant(
                Priority2ChildConstFixture::class,
                'CHILD_LIMIT',
                'parent::PARENT_LIMIT',
                __FILE__,
                21
            );

            expect($valid)->toBe(500);

            $res = RuntimeTypeChecker::checkConstant(
                Priority2ChildConstFixture::class . '::CHILD_LIMIT',
                999,
                'parent::PARENT_LIMIT',
                __FILE__,
                21
            );

            expect($res)->toBeInstanceOf(ErrorMessage::class)
                ->and($res->getMessage())->toContain('must be literal ' . Priority2ParentConstFixture::class . '::PARENT_LIMIT')
            ;
        });

        test('resolves parent::CONSTANT and parent::PROTECTED_LIMIT end-to-end via StreamWrapper', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p2_parent_const_' . uniqid();
            mkdir($tempDir, 0777, true);
            $validFile = $tempDir . '/parent_const_valid.php';
            $invalidFile = $tempDir . '/parent_const_invalid.php';

            $validCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

class ParentConstBaseFixture
{
    public const PARENT_LIMIT = 500;

    protected const PROTECTED_LIMIT = 300;
}

class ParentConstChildValidFixture extends ParentConstBaseFixture
{
    /**
     * @var parent::PARENT_LIMIT
     */
    public const CHILD_LIMIT = 500;

    /**
     * @var parent::PROTECTED_LIMIT
     */
    protected const CHILD_PROTECTED_LIMIT = 300;
}
PHP;

            $invalidCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

class ParentConstInvalidBaseFixture
{
    protected const PROTECTED_LIMIT = 300;
}

class ParentConstChildInvalidFixture extends ParentConstInvalidBaseFixture
{
    /**
     * @var parent::PROTECTED_LIMIT
     */
    public const CHILD_LIMIT = 999;
}
PHP;

            file_put_contents($validFile, $validCode);
            file_put_contents($invalidFile, $invalidCode);

            try {
                StreamWrapper::register();

                expect(fn() => require $validFile)->not()->toThrow(TypeError::class)
                    ->and(fn() => require $invalidFile)->toThrow(TypeError::class, 'PROTECTED_LIMIT')
                ;
            } finally {
                @unlink($validFile);
                @unlink($invalidFile);
                @rmdir($tempDir);
            }
        });

        test('resolves class-level @phpstan-type and @psalm-type aliases in class constant checks', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p2_alias_' . uniqid();
            mkdir($tempDir, 0777, true);
            $validFile = $tempDir . '/alias_const_valid.php';
            $invalidFile = $tempDir . '/alias_const_invalid.php';
            $invalidPrivateFile = $tempDir . '/alias_private_const_invalid.php';

            $validCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @phpstan-type StatusCode positive-int
 * @psalm-type Label non-empty-string
 */
class AliasConstValidFixture
{
    /**
     * @var StatusCode
     */
    public const ALIASED_CODE = 200;

    /**
     * @var Label
     */
    private const ALIASED_LABEL = 'ok';
}
PHP;

            $invalidCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @phpstan-type StatusCode positive-int
 */
class AliasConstInvalidFixture
{
    /**
     * @var StatusCode
     */
    public const BAD_CODE = -5;
}
PHP;

            $invalidPrivateCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @psalm-type Label non-empty-string
 */
class AliasPrivateConstInvalidFixture
{
    /**
     * @var Label
     */
    private const BAD_LABEL = '';
}
PHP;

            file_put_contents($validFile, $validCode);
            file_put_contents($invalidFile, $invalidCode);
            file_put_contents($invalidPrivateFile, $invalidPrivateCode);

            try {
                StreamWrapper::register();

                expect(fn() => require $validFile)->not()->toThrow(TypeError::class)
                    ->and(fn() => require $invalidFile)->toThrow(TypeError::class, 'positive-int')
                    ->and(fn() => require $invalidPrivateFile)->toThrow(TypeError::class, 'non-empty-string')
                ;
            } finally {
                @unlink($validFile);
                @unlink($invalidFile);
                @unlink($invalidPrivateFile);
                @rmdir($tempDir);
            }
        });

        test('resolves @phpstan-import-type and aliases referencing self:: constants in class constants', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p2_import_alias_' . uniqid();
            mkdir($tempDir, 0777, true);
            $validFile = $tempDir . '/import_alias_valid.php';
            $invalidFile = $tempDir . '/import_alias_invalid.php';
            $invalidMaskFile = $tempDir . '/import_alias_mask_invalid.php';

            $validCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @phpstan-type SharedPort int<1, 65535>
 */
class AliasSourceFixture
{
}

/**
 * @phpstan-import-type SharedPort from AliasSourceFixture
 * @phpstan-type FlagMask int-mask-of<self::FLAG_*>
 */
class ImportedAliasConstValidFixture
{
    public const FLAG_READ = 1;
    public const FLAG_WRITE = 2;

    /**
     * @var SharedPort
     */
    public const DEFAULT_PORT = 8080;

    /**
     * @var FlagMask
     */
    public const DEFAULT_FLAGS = 3;
}
PHP;

            $invalidCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @phpstan-type SharedPort int<1, 65535>
 */
class AliasSourceInvalidFixture
{
}

/**
 * @phpstan-import-type SharedPort from AliasSourceInvalidFixture
 */
class ImportedAliasConstInvalidFixture
{
    /**
     * @var SharedPort
     */
    public const BAD_PORT = 70000;
}
PHP;

            $invalidMaskCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @phpstan-type FlagMask int-mask-of<self::FLAG_*>
 */
class ImportedAliasMaskInvalidFixture
{
    public const FLAG_READ = 1;
    public const FLAG_WRITE = 2;

    /**
     * @var FlagMask
     */
    public const BAD_FLAGS = 8;
}
PHP;

            file_put_contents($validFile, $validCode);
            file_put_contents($invalidFile, $invalidCode);
            file_put_contents($invalidMaskFile, $invalidMaskCode);

            try {
                StreamWrapper::register();

                expect(fn() => require $validFile)->not()->toThrow(TypeError::class)
                    ->and(fn() => require $invalidFile)->toThrow(TypeError::class, '<= 65535')
                    ->and(fn() => require $invalidMaskFile)->toThrow(TypeError::class, 'bitmask')
                ;
            } finally {
                @unlink($validFile);
                @unlink($invalidFile);
                @unlink($invalidMaskFile);
                @rmdir($tempDir);
            }
        });

        test('resolves inherited class-level aliases from parent classes in class constant checks', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p2_inherited_alias_' . uniqid();
            mkdir($tempDir, 0777, true);
            $validFile = $tempDir . '/inherited_alias_valid.php';
            $invalidFile = $tempDir . '/inherited_alias_invalid.php';

            $validCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @phpstan-type RetryCount int<1, 5>
 */
class InheritedAliasParentValidFixture
{
}

class InheritedAliasChildValidFixture extends InheritedAliasParentValidFixture
{
    /**
     * @var RetryCount
     */
    public const MAX_RETRIES = 3;

    /**
     * @var RetryCount
     */
    protected const DEFAULT_RETRIES = 1;
}
PHP;

            $invalidCode = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority2;

/**
 * @phpstan-type RetryCount int<1, 5>
 */
class InheritedAliasParentInvalidFixture
{
}

class InheritedAliasChildInvalidFixture extends InheritedAliasParentInvalidFixture
{
    /**
     * @var RetryCount
     */
    public const MAX_RETRIES = 10;
}
PHP;

            file_put_contents($validFile, $validCode);
            file_put_contents($invalidFile, $invalidCode);

            try {
                StreamWrapper::register();

                expect(fn() => require $validFile)->not()->toThrow(TypeError::class)
                    ->and(fn() => require $invalidFile)->toThrow(TypeError::class, '<= 5')
                ;
            } finally {
                @unlink($validFile);
                @unlink($invalidFile);
                @rmdir($tempDir);
            }
        });
    });
});
