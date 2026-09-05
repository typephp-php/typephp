<?php

declare(strict_types=1);

use TypePHP\Internal\Io\StreamWrapper;

describe('Line Number Preservation', function () {
    test('transforms code without shifting original line numbers for parameter checks', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @param positive-int $number
 */
function number(int $number): int
{
    return $number;
}

number(-5);
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_params.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        // Total number of lines in transformed source MUST match original source
        expect(\count($transLines))->toBe(\count($origLines));

        // The line containing 'number(-5);' must be on the exact same line index
        $origCallLine = array_search('number(-5);', array_map('trim', $origLines), true);
        $transCallLine = array_search('number(-5);', array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine);
    });

    test('transforms constructor property promotion without shifting caller line numbers', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class Numbers
{
    /**
     * @param int[] $numbers
     */
    public function __construct(public array $numbers)
    {
    }
}

new Numbers(['a', 'b', 'c', 1]);
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_cpm.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origCallLine = array_search("new Numbers(['a', 'b', 'c', 1]);", array_map('trim', $origLines), true);
        $transCallLine = array_search("new Numbers(['a', 'b', 'c', 1]);", array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine);
    });

    test('transforms single-line empty methods and constructors without shifting line numbers', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class SingleLineBlocks
{
    public function __construct() {}

    public function emptyMethod(): void {}
    
    /** @param string $val */
    public function doNothing(string $val) {}
}

$obj = new SingleLineBlocks();
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_single_line.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origCallLine = array_search('$obj = new SingleLineBlocks();', array_map('trim', $origLines), true);
        $transCallLine = array_search('$obj = new SingleLineBlocks();', array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine);
    });

    test('transforms generic single-line constructors perfectly (Edge Case Reproduction)', function () {
        $source = <<<'PHP'
<?php

namespace App;

/**
 * Covariant Producer Wrapper with single-line constructor
 * 
 * @template-covariant T
 */
class Producer
{
    /** @param T $item */
    public function __construct(public mixed $item) {} 
}

$p = new Producer('test');
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_producer.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origCallLine = array_search("\$p = new Producer('test');", array_map('trim', $origLines), true);
        $transCallLine = array_search("\$p = new Producer('test');", array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine);
    });

    test('preserves executable code when single line comments precede injected statements', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @return positive-int
 */
function testTrailingCommentFunc(int $id): int
{
    $val = $id;
    // Single line trailing comment before return
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_comment.php');

        expect($transformed)->toContain('/* Single line trailing comment before return */')
            ->and($transformed)->toContain('RuntimeTypeChecker::checkReturn')
        ;
    });

    test('does not corrupt URLs containing double slashes in string literals', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class Example
{
    public function show(): void
    {
        echo 'https://example.test/path';
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_url.php');

        expect($transformed)->toContain("'https://example.test/path'")
            ->and($transformed)->not()->toContain('https:/*')
        ;

        $tokens = token_get_all($transformed);
        expect($tokens)->toBeArray();
    });

    test('does not corrupt strings containing hashtags in string literals', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class HashExample
{
    public function show(): void
    {
        $color = '#FF0000';
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_hash.php');

        expect($transformed)->toContain("'#FF0000'")
            ->and($transformed)->not()->toContain('/*')
        ;
    });
});
