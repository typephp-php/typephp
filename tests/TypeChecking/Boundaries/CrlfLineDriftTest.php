<?php

declare(strict_types=1);

use TypePHP\Internal\StreamWrapper;

describe('CRLF (\r\n) Windows Line-Drift Stress Test', function () {
    test('transforms CRLF (\r\n) functions with zero line-drift', function () {
        $source = "<?php\r\n"
            . "\r\n"
            . "declare(strict_types=1);\r\n"
            . "\r\n"
            . "/**\r\n"
            . " * @param positive-int \$id\r\n"
            . " * @param non-empty-string \$name\r\n"
            . " * @return non-empty-string\r\n"
            . " */\r\n"
            . "function formatUserData(int \$id, string \$name): string\r\n"
            . "{\r\n"
            . "    return \"user_{\$id}_{\$name}\";\r\n"
            . "}\r\n"
            . "\r\n"
            . "formatUserData(-5, 'Alice');\r\n";

        $transformed = StreamWrapper::transformSource($source, 'test_crlf_function.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(count($transLines))->toBe(count($origLines));

        $origCallLine = array_search("formatUserData(-5, 'Alice');", array_map('trim', $origLines), true);
        $transCallLine = array_search("formatUserData(-5, 'Alice');", array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine)
            ->and($origCallLine)->toBe(14); 

        $origReturnLine = array_search('return "user_{$id}_{$name}";', array_map('trim', $origLines), true);
        expect($origReturnLine)->toBe(11)
            ->and($transLines[11])->toContain('RuntimeTypeChecker::checkReturn');
    });

    test('transforms CRLF (\r\n) constructor property promotion with zero line-drift', function () {
        $source = "<?php\r\n"
            . "\r\n"
            . "declare(strict_types=1);\r\n"
            . "\r\n"
            . "class CrlfOrder\r\n"
            . "{\r\n"
            . "    /**\r\n"
            . "     * @param positive-int \$orderId\r\n"
            . "     * @param non-empty-string \$sku\r\n"
            . "     */\r\n"
            . "    public function __construct(public int \$orderId, public string \$sku)\r\n"
            . "    {\r\n"
            . "    }\r\n"
            . "}\r\n"
            . "\r\n"
            . "new CrlfOrder(-1, 'SKU-100');\r\n";

        $transformed = StreamWrapper::transformSource($source, 'test_crlf_cpm.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(count($transLines))->toBe(count($origLines));

        $origCallLine = array_search("new CrlfOrder(-1, 'SKU-100');", array_map('trim', $origLines), true);
        $transCallLine = array_search("new CrlfOrder(-1, 'SKU-100');", array_map('trim', $transLines), true);

        expect($transCallLine)->toBe($origCallLine)
            ->and($origCallLine)->toBe(15);
    });

    test('transforms CRLF (\r\n) multi-line inline @var destructuring with zero line-drift', function () {
        $source = "<?php\r\n"
            . "\r\n"
            . "/**\r\n"
            . " * @var positive-int \$id\r\n"
            . " * @var non-empty-string \$username\r\n"
            . " */\r\n"
            . "[\$id, \$username] = [10, 'Alice'];\r\n"
            . "\r\n"
            . "\$targetLine = true;\r\n";

        $transformed = StreamWrapper::transformSource($source, 'test_crlf_destructure.php');

        $origLines = explode("\n", str_replace("\r\n", "\n", $source));
        $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

        expect(count($transLines))->toBe(count($origLines));

        $origTargetLine = array_search('$targetLine = true;', array_map('trim', $origLines), true);
        $transTargetLine = array_search('$targetLine = true;', array_map('trim', $transLines), true);

        expect($transTargetLine)->toBe($origTargetLine)
            ->and($origTargetLine)->toBe(8);
    });

    test('preserves exact line numbers in actual TypeError exceptions thrown from CRLF files', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_crlf_test';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0777, true);
        }

        $crlfScriptPath = $tempDir . '/crlf_runtime_test.php';

        // Line 1:  <?php
        // Line 2:
        // Line 3:  declare(strict_types=1);
        // Line 4:
        // Line 5:  /**
        // Line 6:   * @param positive-int $code
        // Line 7:   */
        // Line 8:  function executeCrlfCheck(int $code): int
        // Line 9:  {
        // Line 10:     return $code;
        // Line 11: }
        // Line 12:
        // Line 13: executeCrlfCheck(-99); // Throws on Line 13!
        $fileContent = "<?php\r\n"
            . "\r\n"
            . "declare(strict_types=1);\r\n"
            . "\r\n"
            . "/**\r\n"
            . " * @param positive-int \$code\r\n"
            . " */\r\n"
            . "function executeCrlfCheck(int \$code): int\r\n"
            . "{\r\n"
            . "    return \$code;\r\n"
            . "}\r\n"
            . "\r\n"
            . "executeCrlfCheck(-99);\r\n";

        file_put_contents($crlfScriptPath, $fileContent);

        try {
            require $crlfScriptPath;
            $caught = false;
        } catch (TypeError $e) {
            $caught = true;
            expect($e->getLine())->toBe(13)
                ->and(str_replace('\\', '/', $e->getFile()))->toBe(str_replace('\\', '/', $crlfScriptPath));
        } finally {
            @unlink($crlfScriptPath);
            @rmdir($tempDir);
        }

        expect($caught)->toBeTrue();
    });
});