<?php

declare(strict_types=1);

namespace TypePHP\Tests\Contract;

use PhpParser\NodeTraverser;
use TypePHP\Internal\Ast\ContractVisitor;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\FileFilter;
use TypePHP\Internal\Util\PathMatcher;

describe('View Template, Framework Cache & Constructor Synthesis Regression Tests', function () {
    describe('1. View Template & Framework Cache Exclusions (FileFilter & PathMatcher)', function () {
        test('FileFilter excludes view templates (.view.php, .blade.php, .html.php, .phtml)', function () {
            $viewFiles = [
                '/app/views/user.view.php',
                '/packages/view/src/Components/x-form.view.php',
                '/resources/views/welcome.blade.php',
                '/templates/layout.html.php',
                '/templates/header.phtml',
            ];

            foreach ($viewFiles as $file) {
                expect(FileFilter::isFileExcluded($file))->toBeTrue("Expected {$file} to be excluded");
            }
        });

        test('FileFilter excludes framework internal cache and compiled view directories', function () {
            $frameworkCacheFiles = [
                '/home/user/project/.tempest/test_internal_storage/2/cache/views/d2690c032e1ea95f.php',
                '/var/www/project/storage/framework/views/87587aadab5fcf86.php',
                '/project/var/cache/views/compiled_view.php',
            ];

            foreach ($frameworkCacheFiles as $file) {
                expect(FileFilter::isFileExcluded($file))->toBeTrue("Expected {$file} to be excluded");
            }
        });

        test('PathMatcher::mayPathBeIncluded returns false for view templates and framework caches', function () {
            expect(PathMatcher::mayPathBeIncluded('/app/views/form.view.php'))->toBeFalse()
                ->and(PathMatcher::mayPathBeIncluded('/views/home.blade.php'))->toBeFalse()
                ->and(PathMatcher::mayPathBeIncluded('/.tempest/cache/views/abc.php'))->toBeFalse()
                ->and(PathMatcher::mayPathBeIncluded('/storage/framework/views/def.php'))->toBeFalse()
            ;
        });

        test('standard PHP files remain included when view templates are bypassed', function () {
            $projectRoot = Config::getProjectRoot();
            $standardPhpFile = str_replace('\\', '/', $projectRoot . '/src/Services/UserService.php');

            expect(FileFilter::isFileExcluded($standardPhpFile))->toBeFalse();
        });
    });

    describe('2. StreamWrapper Direct Handle Passthrough for Templates', function () {
        test('bypasses AST transformation and serves raw view template content on include', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_view_test_' . uniqid();
            mkdir($tempDir, 0777, true);
            $templateFile = $tempDir . '/x-button.view.php';

            $rawTemplate = <<<'HTML'
<a href="https://example.com" :if="$show" @click="handleClick">
    <x-icon name="check" />
    <?= $label ?>
</a>
HTML;
            file_put_contents($templateFile, $rawTemplate);

            try {
                $wrapper = new StreamWrapper();
                $openedPath = null;

                $opened = $wrapper->stream_open($templateFile, 'r', StreamWrapper::STREAM_OPEN_FOR_INCLUDE, $openedPath);
                expect($opened)->toBeTrue();

                $readContent = $wrapper->stream_read(10000);
                $wrapper->stream_close();

                expect($readContent)->toBe($rawTemplate)
                    ->and($readContent)->not()->toContain('hhref')
                    ->and($readContent)->not()->toContain('RuntimeTypeChecker')
                ;
            } finally {
                @unlink($templateFile);
                @rmdir($tempDir);
            }
        });

        test('bypasses AST transformation for compiled view cache inside .tempest directory', function () {
            $tempDir = sys_get_temp_dir() . '/.tempest/cache/views';
            mkdir($tempDir, 0777, true);
            $compiledCacheFile = $tempDir . '/compiled_view_123.php';

            $compiledContent = '<?php return function() { echo "<a href=\"https://\"></a>"; };';
            file_put_contents($compiledCacheFile, $compiledContent);

            try {
                $wrapper = new StreamWrapper();
                $openedPath = null;

                $opened = $wrapper->stream_open($compiledCacheFile, 'r', StreamWrapper::STREAM_OPEN_FOR_INCLUDE, $openedPath);
                expect($opened)->toBeTrue();

                $readContent = $wrapper->stream_read(10000);
                $wrapper->stream_close();

                expect($readContent)->toBe($compiledContent);
            } finally {
                @unlink($compiledCacheFile);
                @rmdir($tempDir);
                @rmdir(\dirname($tempDir));
                @rmdir(\dirname($tempDir, 2));
            }
        });
    });

    describe('3. Constructor Synthesis Safety (ContractVisitor)', function () {
        test('does NOT inject __construct when class extends a parent class', function () {
            $code = <<<'PHP'
<?php
class CustomTestCase extends \PHPUnit\Framework\TestCase
{
    /** @var positive-int */
    public int $counter = 1;
}
PHP;

            $stmts = StreamWrapper::getParser()->parse($code);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ContractVisitor());
            $newStmts = $traverser->traverse($stmts);

            $transformed = StreamWrapper::getPrinter()->prettyPrint($newStmts);

            expect($transformed)->not()->toContain('function __construct');
        });

        test('does NOT inject __construct when class is abstract', function () {
            $code = <<<'PHP'
<?php
abstract class AbstractBaseModel
{
    /** @var non-empty-string */
    public string $name = 'default';
}
PHP;

            $stmts = StreamWrapper::getParser()->parse($code);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ContractVisitor());
            $newStmts = $traverser->traverse($stmts);

            $transformed = StreamWrapper::getPrinter()->prettyPrint($newStmts);

            expect($transformed)->not()->toContain('function __construct');
        });

        test('injects __construct with zero parameters (params: []) on standalone concrete classes', function () {
            $code = <<<'PHP'
<?php
class StandaloneConfig
{
    /** @var positive-int */
    public int $retries = 3;
}
PHP;

            $stmts = StreamWrapper::getParser()->parse($code);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ContractVisitor());
            $newStmts = $traverser->traverse($stmts);

            $transformed = StreamWrapper::getPrinter()->prettyPrint($newStmts);

            expect($transformed)->toContain('function __construct()')
                ->and($transformed)->not()->toContain('$_typephp_ctor_args')
                ->and($transformed)->toContain("RuntimeTypeChecker::checkProperty(\$this->retries, \$this, 'retries'")
            ;
        });

        test('preserves and injects checks into existing constructors without altering signatures', function () {
            $code = <<<'PHP'
<?php
class ClassWithExplicitConstructor extends BaseClass
{
    /** @var positive-int */
    public int $limit = 10;

    public function __construct(string $name, array $options = [])
    {
        parent::__construct($name);
    }
}
PHP;

            $stmts = StreamWrapper::getParser()->parse($code);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ContractVisitor());
            $newStmts = $traverser->traverse($stmts);

            $transformed = StreamWrapper::getPrinter()->prettyPrint($newStmts);

            expect($transformed)->toContain('function __construct(string $name, array $options = [])')
                ->and($transformed)->toContain("RuntimeTypeChecker::checkProperty(\$this->limit, \$this, 'limit'")
            ;
        });
    });

    describe('4. Post-Increment Expression Semantics in Parsers/Iterators', function () {
        test('preserves post-increment semantics inside array dimension lookup ($arr[$i++])', function () {
            $source = <<<'PHP'
<?php
$data = ['first', 'second', 'third'];
$i = 0;
$result = $data[$i++];
PHP;

            $stmts = StreamWrapper::getParser()->parse($source);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ContractVisitor());
            $newStmts = $traverser->traverse($stmts);

            $transformed = StreamWrapper::getPrinter()->prettyPrint($newStmts);

            expect($transformed)->toContain('$data[$i++]')
                ->and($transformed)->not()->toContain('$i = $i + 1')
            ;
        });

        test('preserves post-increment on object properties inside array dimension ($arr[$this->cursor++])', function () {
            $source = <<<'PHP'
<?php
class Tokenizer
{
    public int $cursor = 0;
    public array $buffer = ['<', 'a', '>'];

    public function consume(): string
    {
        return $this->buffer[$this->cursor++];
    }
}
PHP;

            $stmts = StreamWrapper::getParser()->parse($source);
            $traverser = new NodeTraverser();
            $traverser->addVisitor(new ContractVisitor());
            $newStmts = $traverser->traverse($stmts);

            $transformed = StreamWrapper::getPrinter()->prettyPrint($newStmts);

            expect($transformed)->toContain('$this->buffer[$this->cursor++]')
                ->and($transformed)->not()->toContain('checkProperty($this->cursor + 1')
            ;
        });
    });
});
