<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

describe('Priority 4 Bugs: Sibling Scope Isolation & Docblock Prose Heuristics (Red Phase)', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Bug 9: Sibling Catch_, Finally_, Switch_/Case_, and If_/Else_ Scope Bleed', function () {
        test('does not bleed @var between sibling catch blocks or into finally', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p4_trycatch_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/try_catch_scopes.php';

            $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority4;

use LogicException;
use RuntimeException;

class TryCatchScopeFixture
{
    public static function catchToSiblingCatch(): int
    {
        try {
            throw new LogicException('second');
        } catch (RuntimeException $e) {
            /** @var positive-int $val */
            $val = 10;

            return $val;
        } catch (LogicException $e) {
            $val = -1;

            return $val;
        }
    }

    public static function tryAndCatchToFinally(): string
    {
        try {
            /** @var positive-int $tryOnly */
            $tryOnly = 1;
            throw new RuntimeException('boom');
        } catch (RuntimeException $e) {
            /** @var non-empty-string $catchOnly */
            $catchOnly = 'ok';
            $tryOnly = -5;
        } finally {
            $tryOnly = -10;
            $catchOnly = '';
        }

        return $tryOnly . ':' . $catchOnly;
    }

    public static function ownCatchBlockStillEnforced(): void
    {
        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $e) {
            /** @var positive-int $local */
            $local = -1;
        }
    }

    public static function outerScopeStillEnforcedInCatch(): void
    {
        /** @var positive-int $outer */
        $outer = 1;

        try {
            throw new RuntimeException('boom');
        } catch (RuntimeException $e) {
            $outer = -1;
        }
    }
}
PHP;

            file_put_contents($filePath, $code);

            try {
                StreamWrapper::register();
                require $filePath;

                expect(\App\Priority4\TryCatchScopeFixture::catchToSiblingCatch())->toBe(-1)
                    ->and(\App\Priority4\TryCatchScopeFixture::tryAndCatchToFinally())->toBe('-10:')
                    ->and(fn () => \App\Priority4\TryCatchScopeFixture::ownCatchBlockStillEnforced())
                    ->toThrow(TypeError::class, 'positive-int')
                    ->and(fn () => \App\Priority4\TryCatchScopeFixture::outerScopeStillEnforcedInCatch())
                    ->toThrow(TypeError::class, 'positive-int')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('does not bleed @var between switch cases or after switch statement', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p4_switch_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/switch_scopes.php';

            $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority4;

class SwitchScopeFixture
{
    public static function siblingCases(int $kind): int
    {
        switch ($kind) {
            case 1:
                /** @var positive-int $item */
                $item = 5;

                return $item;
            case 2:
                $item = -99;

                return $item;
            default:
                $item = 0;

                return $item;
        }
    }

    public static function afterSwitch(int $kind): int
    {
        switch ($kind) {
            case 1:
                /** @var positive-int $scoped */
                $scoped = 5;
                break;
        }

        $scoped = -1;

        return $scoped;
    }

    public static function ownCaseStillEnforced(): void
    {
        switch (1) {
            case 1:
                /** @var positive-int $bad */
                $bad = -5;
                break;
        }
    }
}
PHP;

            file_put_contents($filePath, $code);

            try {
                StreamWrapper::register();
                require $filePath;

                expect(\App\Priority4\SwitchScopeFixture::siblingCases(2))->toBe(-99)
                    ->and(\App\Priority4\SwitchScopeFixture::siblingCases(3))->toBe(0)
                    ->and(\App\Priority4\SwitchScopeFixture::afterSwitch(1))->toBe(-1)
                    ->and(fn () => \App\Priority4\SwitchScopeFixture::ownCaseStillEnforced())
                    ->toThrow(TypeError::class, 'positive-int')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('does not bleed @var from if branch into elseif or else branches', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p4_if_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/if_scopes.php';

            $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority4;

class IfElseScopeFixture
{
    public static function pick(int $mode): int
    {
        if ($mode === 1) {
            /** @var positive-int $n */
            $n = 10;

            return $n;
        } elseif ($mode === 2) {
            $n = -2;

            return $n;
        } else {
            $n = -3;

            return $n;
        }
    }
}
PHP;

            file_put_contents($filePath, $code);

            try {
                StreamWrapper::register();
                require $filePath;

                expect(\App\Priority4\IfElseScopeFixture::pick(1))->toBe(10)
                    ->and(\App\Priority4\IfElseScopeFixture::pick(2))->toBe(-2)
                    ->and(\App\Priority4\IfElseScopeFixture::pick(3))->toBe(-3)
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });
    });

    describe('Bug 10: Docblock Prose Heuristics & Multi-Line Tags in FunctionContractInjector', function () {
        test('does not inject wrapCallable or setupScope when prose mentions callable, Closure, or $this', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority4;

/**
 * Formats a value that is not callable and not a Closure.
 * Unlike $this style helpers, this has no @return or @param contract.
 */
function describePlainArgs(mixed $x, int $count): string
{
    return get_debug_type($x) . ':' . $count;
}

/**
 * Ensures input is treated as non-callable text (not a Closure).
 *
 * @param string $label
 */
function formatPlainLabel(string $label, int $id): string
{
    return $label . '#' . $id;
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_prose_heuristics.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::wrapCallable')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
            ;
        });

        test('enforces real @return tag even when prose contains @return mixed earlier in the docblock', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p4_prose_ret_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/prose_return.php';

            $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority4;

/**
 * Legacy v1 used @return mixed here, now tightened.
 *
 * @return positive-int
 */
function getTightenedPositive(): int
{
    return -42;
}

/**
 * Legacy v1 used @return mixed here, now tightened.
 *
 * @return
 *     positive-int
 */
function getMultilinePositive(): int
{
    return -7;
}
PHP;

            $transformed = StreamWrapper::transformSource($code, $filePath);
            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');

            file_put_contents($filePath, $code);

            try {
                StreamWrapper::register();
                require $filePath;

                expect(fn () => \App\Priority4\getTightenedPositive())
                    ->toThrow(TypeError::class, 'positive-int')
                    ->and(fn () => \App\Priority4\getMultilinePositive())
                    ->toThrow(TypeError::class, 'positive-int')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('does not inject unnecessary setupScope, wrapIterable, or checkSelfOut from prose keywords', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority4;

final class ProseMethodFixture
{
    /**
     * Checks whether input is iterable or uses self-out conventions in prose.
     * Note: $this is fine when @param mixed is written in legacy docs.
     *
     * @param
     *     mixed $raw
     *
     * @return int | mixed
     */
    public function acceptMixed(mixed $raw, int $flag): int
    {
        return $flag;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_prose_method.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::wrapIterable')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkSelfOut')
            ;
        });
    });
});
