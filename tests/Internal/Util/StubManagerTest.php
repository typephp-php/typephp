<?php

declare(strict_types=1);

use PhpParser\Node\Stmt\Class_;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StubManager;

describe('StubManager Unit Tests', function () {
    afterEach(function () {
        StubManager::reset();
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

            StubManager::reset();
            Config::reset();
        }
    });

    test('resolveStubFiles covers direct file, directory path, wildcard baseDir fallback, and non-existent path', function () {
        $ref = new ReflectionClass(StubManager::class);
        $method = $ref->getMethod('resolveStubFiles');

        $baseTemp = sys_get_temp_dir() . '/typephp_resolve_stubs_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $realTemp = realpath($baseTemp);
        $tempDir = str_replace('\\', '/', $realTemp !== false ? $realTemp : $baseTemp);

        $targetFile = $tempDir . '/dummy.stub';
        file_put_contents($targetFile, '<?php');
        $realFile = realpath($targetFile);
        $dummyFile = str_replace('\\', '/', $realFile !== false ? $realFile : $targetFile);

        try {
            $resFile = $method->invoke(null, $dummyFile, $tempDir);
            expect($resFile)->toBe([$dummyFile]);

            $resDir = $method->invoke(null, $tempDir, $tempDir);
            expect($resDir)->toContain($dummyFile);

            $resMissingBase = $method->invoke(null, $tempDir . '/missing_dir_123/sub/*.stub', $tempDir);
            expect($resMissingBase)->toBe([]);

            $resMissingFile = $method->invoke(null, $tempDir . '/non_existent_file.stub', $tempDir);
            expect($resMissingFile)->toBe([]);
        } finally {
            @unlink($targetFile);
            @rmdir($baseTemp);
        }
    });

    test('loadStubFiles catches parse errors on malformed stub files', function () {
        $ref = new ReflectionClass(StubManager::class);
        $method = $ref->getMethod('loadStubFiles');

        $tempDir = sys_get_temp_dir() . '/typephp_load_stubs_' . uniqid();
        mkdir($tempDir, 0777, true);

        $badSyntaxFile = $tempDir . '/syntax_error.stub';
        file_put_contents($badSyntaxFile, '<?php syntax error {{{ unclosed');

        try {
            $method->invoke(null, [$badSyntaxFile]);

            expect(StubManager::hasClassStub('SyntaxError'))->toBeFalse();
        } finally {
            @unlink($badSyntaxFile);
            @rmdir($tempDir);
        }
    });

    test('extractStubsFromAst extracts interfaces, traits, enums and skips anonymous classes', function () {
        $ref = new ReflectionClass(StubManager::class);
        $method = $ref->getMethod('extractStubsFromAst');

        $code = <<<'PHP'
<?php

namespace Vendor\AstTest;

/**
 * @template T
 */
interface AstStubInterface
{
    /**
     * @return positive-int
     */
    public function getId(): int;
}

/**
 * @template T
 */
trait AstStubTrait
{
    /**
     * @var positive-int
     */
    public int $counter;

    /**
     * @return non-empty-string
     */
    public function getName(): string
    {
    }
}

/**
 * Enum doc
 */
enum AstStubEnum: string
{
    case A = 'a';
}
PHP;

        $parser = StreamWrapper::getParser();
        $stmts = $parser->parse($code);
        expect($stmts)->not()->toBeNull();

        $method->invoke(null, $stmts);

        expect(StubManager::hasClassStub('Vendor\AstTest\AstStubInterface'))->toBeTrue()
            ->and(StubManager::hasMethodStub('Vendor\AstTest\AstStubInterface', 'getId'))->toBeTrue()
            ->and(StubManager::hasClassStub('Vendor\AstTest\AstStubTrait'))->toBeTrue()
            ->and(StubManager::getPropertyDoc('Vendor\AstTest\AstStubTrait', 'counter'))->toContain('@var positive-int')
            ->and(StubManager::getMethodDoc('Vendor\AstTest\AstStubTrait', 'getName'))->toContain('@return non-empty-string')
            ->and(StubManager::hasClassStub('Vendor\AstTest\AstStubEnum'))->toBeTrue()
            ->and(StubManager::getClassDoc('Vendor\AstTest\AstStubEnum'))->toContain('Enum doc')
        ;
        $method->invoke(null, [new Class_(null)]);
    });
});
