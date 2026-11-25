<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

describe('Inline @var on return vs FunctionContractInjector', function () {
    beforeEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    afterEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    test('does not double-wrap unnamed /** @var T */ return $x; when @var satisfies @return', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @return positive-int
 */
function returnUnnamedMatchingVar(mixed $x): int
{
    /** @var positive-int */
    return $x;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'return_unnamed_matching.php');

        expect($transformed)->toContain("RuntimeTypeChecker::checkVariable(\$x, 'positive-int', 'return'")
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
        ;
    });

    test('enforces named /** @var positive-int $x */ return $x; instead of silently dropping it', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\ReturnVarFixture;

/**
 * @return int
 */
function returnNamedNarrowerVar(mixed $x): int
{
    /** @var positive-int $x */
    return $x;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'return_named_narrower.php');

        expect($transformed)->toContain("RuntimeTypeChecker::checkVariable(\$x, 'positive-int', 'x'")
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
        ;

        $baseTemp = sys_get_temp_dir() . '/typephp_ret_named_var_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
        $filePath = $tempDir . '/named_var_return.php';
        file_put_contents($filePath, $source);

        try {
            Config::set([
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            require_once $filePath;

            expect(\TypePHP\Tests\Internal\Ast\ReturnVarFixture\returnNamedNarrowerVar(5))->toBe(5);

            expect(fn () => \TypePHP\Tests\Internal\Ast\ReturnVarFixture\returnNamedNarrowerVar(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            @unlink($filePath);
            @rmdir($tempDir);
        }
    });

    test('still enforces stricter @return contract when inline @var is wider, with checkVariable inside checkReturn', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\ReturnVarWiderFixture;

/**
 * @return positive-int
 */
function returnWiderInlineVar(mixed $x): int
{
    /** @var int */
    return $x;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'return_wider_var.php');

        $varPos = strpos($transformed, "RuntimeTypeChecker::checkVariable(\$x, 'int', 'return'");
        $retPos = strpos($transformed, 'RuntimeTypeChecker::checkReturn');

        expect($transformed)->toContain("RuntimeTypeChecker::checkVariable(\$x, 'int', 'return'")
            ->and($varPos)->toBeInt()
            ->and($retPos)->toBeInt()
            ->and($varPos)->toBeLessThan($retPos)
        ;

        $baseTemp = sys_get_temp_dir() . '/typephp_ret_wider_var_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
        $filePath = $tempDir . '/wider_var_return.php';
        file_put_contents($filePath, $source);

        try {
            Config::set([
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            require_once $filePath;

            expect(\TypePHP\Tests\Internal\Ast\ReturnVarWiderFixture\returnWiderInlineVar(5))->toBe(5);

            expect(fn () => \TypePHP\Tests\Internal\Ast\ReturnVarWiderFixture\returnWiderInlineVar('not-an-int'))
                ->toThrow(TypeError::class, 'must be of type int')
            ;

            expect(fn () => \TypePHP\Tests\Internal\Ast\ReturnVarWiderFixture\returnWiderInlineVar(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            @unlink($filePath);
            @rmdir($tempDir);
        }
    });

    test('preserves @param-out checks before /** @var T */ return $x;', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\ReturnVarParamOutFixture;

/**
 * @param mixed $val
 * @param mixed &$out
 *
 * @param-out positive-int $out
 *
 * @return positive-int
 */
function returnVarWithParamOut(mixed $val, mixed &$out): int
{
    $out = -1;

    /** @var positive-int */
    return $val;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'return_var_param_out.php');

        expect($transformed)->toContain('RuntimeTypeChecker::checkParamOut')
            ->and($transformed)->toContain("RuntimeTypeChecker::checkVariable(\$val, 'positive-int', 'return'")
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
        ;

        $baseTemp = sys_get_temp_dir() . '/typephp_ret_paramout_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
        $filePath = $tempDir . '/param_out_var_return.php';
        file_put_contents($filePath, $source);

        try {
            Config::set([
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            require_once $filePath;

            $out = 10;
            expect(fn () => \TypePHP\Tests\Internal\Ast\ReturnVarParamOutFixture\returnVarWithParamOut(5, $out))
                ->toThrow(TypeError::class, 'param-out')
            ;
        } finally {
            @unlink($filePath);
            @rmdir($tempDir);
        }
    });

    test('does not double-wrap generator return when /** @var T */ satisfies TReturn', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @return \Generator<int, string, mixed, positive-int>
 */
function genReturnMatchingVar(mixed $x): \Generator
{
    yield 0 => 'ok';

    /** @var positive-int */
    return $x;
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'gen_return_matching_var.php');

        expect($transformed)->toContain("RuntimeTypeChecker::checkVariable(\$x, 'positive-int', 'return'")
            ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkGeneratorReturn')
        ;
    });

    test('still enforces inherited interface @return contract when child method uses wider inline @var on return', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\ReturnVarLspFixture;

interface IdProviderInterface
{
    /**
     * @return positive-int
     */
    public function getId(mixed $raw): int;
}

class IdProviderImpl implements IdProviderInterface
{
    public function getId(mixed $raw): int
    {
        /** @var int */
        return $raw;
    }
}
PHP;

        $baseTemp = sys_get_temp_dir() . '/typephp_ret_lsp_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;
        $filePath = $tempDir . '/lsp_var_return.php';
        file_put_contents($filePath, $source);

        try {
            Config::set([
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            require_once $filePath;

            $impl = new ReturnVarLspFixture\IdProviderImpl();

            expect($impl->getId(10))->toBe(10);

            expect(fn () => $impl->getId(-5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        } finally {
            @unlink($filePath);
            @rmdir($tempDir);
        }
    });
});
