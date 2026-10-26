<?php

declare(strict_types=1);

use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

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

    test('preserves exact line numbers when a multi-line array return is followed by subsequent method calls', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class MultiLineReturnFixture
{
    /**
     * @return array<string, list<int>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
            'is_locked' => ['boolean'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function insert(array $data): void
    {
        // logic
    }
}

$targetCall = true;
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_multiline_return_drift.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origIndex = array_search('$targetCall = true;', array_map('trim', $origLines), true);
        $transIndex = array_search('$targetCall = true;', array_map('trim', $transLines), true);

        expect($transIndex)->toBe($origIndex)
            ->and($origIndex)->toBe(27)
        ;
    });

    test('points to exact return statement start line when multi-line return array contract fails', function () {
        $sysTemp = realpath(sys_get_temp_dir());
        $baseTemp = str_replace('\\', '/', $sysTemp !== false ? $sysTemp : sys_get_temp_dir());
        $tempDir = $baseTemp . '/typephp_multiline_test_' . uniqid();
        mkdir($tempDir, 0777, true);

        $canonicalDir = str_replace('\\', '/', realpath($tempDir) ?: $tempDir);
        $scriptPath = $canonicalDir . '/multiline_return_test.php';

        $code = <<<'PHP'
<?php

declare(strict_types=1);

class ReturnLineCheckFixture
{
    /**
     * @return array<string, list<int>>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
        ];
    }
}

(new ReturnLineCheckFixture())->rules();
PHP;

        file_put_contents($scriptPath, $code);

        try {
            Config::set([
                'include' => [
                    $canonicalDir . '/**',
                ],
                'exclude' => [
                    'vendor/**',
                ],
            ]);
            StreamWrapper::register();

            require $scriptPath;
            $caught = false;
        } catch (TypePHP\Exception\TypeError $e) {
            $caught = true;
            expect($e->getLine())->toBe(12);
        } finally {
            Config::reset();
            if (file_exists($scriptPath)) {
                @unlink($scriptPath);
            }
            if (is_dir($tempDir)) {
                @rmdir($tempDir);
            }
        }

        expect($caught)->toBeTrue();
    });

    test('preserves exact line numbers when a constructor has multi-line parameters and an empty body', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class MultiLineEmptyConstructor
{
    /**
     * @param positive-int $id
     * @param non-empty-string $name
     */
    public function __construct(
        private int $id,
        private string $name
    ) {}
}

$targetCall = true;
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_multiline_empty_ctor.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origIndex = array_search('$targetCall = true;', array_map('trim', $origLines), true);
        $transIndex = array_search('$targetCall = true;', array_map('trim', $transLines), true);

        expect($transIndex)->toBe($origIndex)
            ->and($origIndex)->toBe(16)
        ;
    });

    test('preserves exact line numbers across preceding multi-line methods when a trailing class has a single-line empty {} method with @param', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class PaymentService
{
    /**
     * @param positive-int $amount
     * @param array{gateway: 'stripe'|'paypal', currency: non-empty-string} $options
     * @return non-empty-string
     */
    public function charge(int $amount, array $options): string
    {
        return 'ok';
    }
}

class Report
{
    /**
     * @param array<string, list<int>> $scores
     * @return array<string, int>
     */
    public function totals(array $scores): array
    {
        return array_map('array_sum', $scores);
    }

    /** @return array<string, int> */
    public function broken(): array
    {
        return ['a' => 1, 'b' => 'two'];
    }
}

class Cart
{
    /** @param array<string, int> $items */
    public function insert(array $items): void {}
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_three_classes_drift.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines))
            ->and(trim($transLines[11]))->toBe('public function charge(int $amount, array $options): string')
            ->and(trim($transLines[13]))->toBe("return 'ok';")
            ->and(trim($transLines[17]))->toBe('class Report')
            ->and(trim($transLines[23]))->toBe('public function totals(array $scores): array')
            ->and($transLines[25])->toContain('RuntimeTypeChecker::checkReturn')
            ->and(trim($transLines[29]))->toBe('public function broken(): array')
            ->and($transLines[31])->toContain('RuntimeTypeChecker::checkReturn')
            ->and(trim($transLines[35]))->toBe('class Cart')
            ->and($transLines[38])->toContain('public function insert(array $items): void {')
        ;
    });

    test('distinguishes multi-line empty methods from single-line empty {} methods in the same file without line drift', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class MixedEmptyMethods
{
    /**
     * @param positive-int $id
     */
    public function multiLineEmpty(int $id): void
    {
    }

    /**
     * @param non-empty-string $name
     */
    public function singleLineEmpty(string $name): void {}
}

$targetLineAfterClass = true;
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_mixed_empty_methods.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines))
            ->and(trim($transLines[9]))->toBe('public function multiLineEmpty(int $id): void')
            ->and($transLines[16])->toContain('public function singleLineEmpty(string $name): void {')
            ->and(trim($transLines[19]))->toBe('$targetLineAfterClass = true;')
        ;
    });

    test('STRESS TEST 1: Large file with 7 classes mixing Allman, K&R, 1-line, and empty method styles preserves line numbers 1-to-1', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\StressTest;

class ServiceOne
{
    /**
     * @param positive-int $code
     * @return non-empty-string
     */
    public function allmanStyle(int $code): string
    {
        return "code_{$code}";
    }
}

class ServiceTwo
{
    /**
     * @param non-empty-string $label
     */
    public function sameLineBrace(string $label): void {
        echo $label;
    }

    /** @param int $val */
    public function singleLineEmpty(int $val): void {}
}

interface ContractThree
{
    public function abstractMethod(int $x): string;
}

class ServiceFour implements ContractThree
{
    public function abstractMethod(int $x): string
    {
        return (string) $x;
    }

    /**
     * @param mixed &$id
     * @param-out positive-int $id
     */
    public function paramOutMethod(mixed &$id): void
    {
        $id = 42;
    }
}

class ServiceFive
{
    /**
     * @return \Generator<string, positive-int>
     */
    public function streamRecords(): \Generator
    {
        yield 'first' => 10;
        yield 'second' => 20;
    }
}

class ServiceSix
{
    /** @param positive-int $counter */
    public function emptyMultiLine(int $counter): void
    {
    }
}

class ServiceSeven
{
    /** @param list<positive-int> $items */
    public function trailingEmpty(array $items): void {}
}

$finalLandmarkLine = 'end_of_file_check';
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_mega_stress_drift.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        // Verify landmarks across the entire 90+ line file
        $landmarks = [
            'class ServiceOne',
            'public function allmanStyle(int $code): string',
            'class ServiceTwo',
            'public function sameLineBrace(string $label): void {',
            'interface ContractThree',
            'class ServiceFour implements ContractThree',
            'public function paramOutMethod(mixed &$id): void',
            'class ServiceFive',
            'public function streamRecords(): \Generator',
            'class ServiceSix',
            'public function emptyMultiLine(int $counter): void',
            'class ServiceSeven',
            'public function trailingEmpty(array $items): void {',
            '$finalLandmarkLine = \'end_of_file_check\';',
        ];

        foreach ($landmarks as $landmark) {
            $origIdx = null;
            $transIdx = null;

            foreach ($origLines as $idx => $line) {
                if (str_contains($line, $landmark)) {
                    $origIdx = $idx;

                    break;
                }
            }

            foreach ($transLines as $idx => $line) {
                if (str_contains($line, $landmark)) {
                    $transIdx = $idx;

                    break;
                }
            }

            expect($transIdx)->not()->toBeNull("Failed finding transformed landmark: {$landmark}")
                ->and($transIdx)->toBe($origIdx, "Line drift on landmark: {$landmark}")
            ;
        }
    });

    test('STRESS TEST 2: Preserves line numbers when standalone functions mix with inline @var, compound operators, and destructuring', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @param positive-int $id
 * @return non-empty-string
 */
function firstWorker(int $id): string
{
    /** @var positive-int $localScore */
    $localScore = $id;
    $localScore += 5;

    return "worker_{$localScore}";
}

/**
 * @param array{id: positive-int, name: non-empty-string} $data
 */
function secondWorker(array $data): void
{
    ['id' => $id, 'name' => $name] = $data;
}

/** @param int $opt */
function thirdEmptyWorker(int $opt): void {}

$endOfFunctionStress = 100;
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_functions_stress_drift.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origEnd = array_search('$endOfFunctionStress = 100;', array_map('trim', $origLines), true);
        $transEnd = array_search('$endOfFunctionStress = 100;', array_map('trim', $transLines), true);

        expect($transEnd)->toBe($origEnd);
    });

    test('STRESS TEST 3: Mixed multi-line parameters with trailing {}) followed by methods with DocBlocks', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

class MultiParamClass
{
    /**
     * @param positive-int $a
     * @param non-empty-string $b
     * @param list<int> $c
     */
    public function __construct(
        public int $a,
        public string $b,
        public array $c = []
    ) {}

    /**
     * @param positive-int $multiplier
     * @return positive-int
     */
    public function compute(int $multiplier): int
    {
        return $this->a * $multiplier;
    }
}

$endOfConstructorTest = true;
PHP;

        $transformed = StreamWrapper::transformSource($source, 'test_ctor_multiline_trailing.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(\count($transLines))->toBe(\count($origLines));

        $origCompute = array_search('public function compute(int $multiplier): int', array_map('trim', $origLines), true);
        $transCompute = array_search('public function compute(int $multiplier): int', array_map('trim', $transLines), true);

        expect($transCompute)->toBe($origCompute);

        $origEnd = array_search('$endOfConstructorTest = true;', array_map('trim', $origLines), true);
        $transEnd = array_search('$endOfConstructorTest = true;', array_map('trim', $transLines), true);

        expect($transEnd)->toBe($origEnd);
    });
});