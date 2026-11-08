<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;

describe('Class & Global Constant Contracts (@var on const)', function () {
    describe('1. Class Constant Validation', function () {
        test('throws TypeError when class constant violates list<string> contract (User Reproduction)', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class FixtureBadListConst
{
    /**
     * @var list<string>
     */
    public const array Data = [
        'user' => 'User',
        'product' => 'Product',
    ];
}
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/FixtureBadListConst.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, 'Constant TypePHP\Tests\TypeChecking\Boundaries\FixtureBadListConst::Data must be a list, associative array (key \'user\') given')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('accepts class constant satisfying list<string> contract', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class FixtureValidListConst
{
    /**
     * @var list<string>
     */
    public const array Data = [
        'User',
        'Product',
    ];
}

return FixtureValidListConst::Data;
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/FixtureValidListConst.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                $result = require $filePath;
                expect($result)->toBe(['User', 'Product']);
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('throws TypeError when class constant violates scalar refinement (positive-int)', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class FixtureBadScalarConst
{
    /**
     * @var positive-int
     */
    public const int RETRIES = -5;
}
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/FixtureBadScalarConst.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, 'Constant TypePHP\Tests\TypeChecking\Boundaries\FixtureBadScalarConst::RETRIES must be of type positive-int, negative int (-5) given')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('throws TypeError when class constant violates array shape', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class FixtureBadShapeConst
{
    /**
     * @var array{id: positive-int, role: 'admin'|'user'}
     */
    public const CONFIG = [
        'id' => -1,
        'role' => 'admin',
    ];
}
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/FixtureBadShapeConst.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, "['id'] must be of type positive-int")
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });
    });

    describe('2. Global Constant Validation', function () {
        test('throws TypeError when global const violates non-empty-string contract', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @var non-empty-string
 */
const GLOBAL_BAD_EMPTY_NAME = '';
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/global_const.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, 'Constant GLOBAL_BAD_EMPTY_NAME must be of type non-empty-string')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });
    });

    describe('3. Compile-Time Static Elimination & Zero Line-Drift', function () {
        test('eliminates check on statically proven valid constant (zero injections)', function () {
            $source = <<<'PHP'
<?php

class ValidConfig
{
    /**
     * @var list<string>
     */
    public const Data = ['User', 'Product'];
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'ValidConfig.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkConstant')
                ->and($transformed)->not()->toContain('checkClassConstant')
            ;
        });

        test('guarantees zero line-drift when constant check is injected', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class ConfigWithDriftCheck
{
    /**
     * @var list<string>
     */
    public const array Data = [
        'user' => 'User',
    ];
}

$landmark = 'line_16_exact';
PHP;

            $transformed = StreamWrapper::transformSource($source, 'ConfigWithDriftCheck.php');

            $origLines = explode("\n", str_replace("\r\n", "\n", $source));
            $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

            expect(\count($transLines))->toBe(\count($origLines));

            $origIndex = array_search('$landmark = \'line_16_exact\';', array_map('trim', $origLines), true);
            $transIndex = array_search('$landmark = \'line_16_exact\';', array_map('trim', $transLines), true);

            expect($transIndex)->toBe($origIndex)
                ->and($origIndex)->toBe(16)
            ;
        });

        test('points to exact line number of the constant definition in thrown TypeError', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class ExactLineConfig
{
    /**
     * @var positive-int
     */
    public const int FAILING_LINE = -99;
}
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_line_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/ExactLineConfig.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                try {
                    require $filePath;
                    $caught = false;
                } catch (TypeError $e) {
                    $caught = true;
                    expect($e->getLine())->toBe(12);
                }

                expect($caught)->toBeTrue();
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });
    });

    describe('4. Closures in Constants (PHP 8.5+)', function () {
        test('throws TypeError when constant closure violates return contract', function () {
            if (PHP_VERSION_ID < 80500) {
                expect(true)->toBeTrue();

                return;
            }

            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class ConfigConstClosureReturnViolation
{
    /**
     * @var callable(): int
     */
    public const Callback = static function () {
        return 'not_an_int';
    };
}

$fn = ConfigConstClosureReturnViolation::Callback;
$fn();
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_closure_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/ConfigConstClosureReturnViolation.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, 'ConfigConstClosureReturnViolation::Callback(): Return value must be of type int, string \'not_an_int\' returned')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('throws TypeError on direct execution of constant closure (Config::Callback)()', function () {
            if (PHP_VERSION_ID < 80500) {
                expect(true)->toBeTrue();

                return;
            }

            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class ConfigConstClosureDirectCall
{
    /**
     * @var callable(): int
     */
    public const Callback = static function () {
        return 'not_an_int';
    };
}

(ConfigConstClosureDirectCall::Callback)();
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_closure_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/ConfigConstClosureDirectCall.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, 'ConfigConstClosureDirectCall::Callback(): Return value must be of type int')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('throws TypeError when constant closure parameter violates contract', function () {
            if (PHP_VERSION_ID < 80500) {
                expect(true)->toBeTrue();

                return;
            }

            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class ConfigConstClosureParamViolation
{
    /**
     * @var callable(positive-int $id): string
     */
    public const Formatter = static function (int $id): string {
        return "id_{$id}";
    };
}

$fn = ConfigConstClosureParamViolation::Formatter;
$fn(-5);
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_closure_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/ConfigConstClosureParamViolation.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, 'ConfigConstClosureParamViolation::Formatter(): Argument $id must be of type positive-int, negative int (-5) given')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('throws TypeError when global constant closure violates return contract', function () {
            if (PHP_VERSION_ID < 80500) {
                expect(true)->toBeTrue();

                return;
            }

            $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @var callable(): int
 */
const GLOBAL_CONST_CLOSURE = static function () {
    return 'invalid';
};

$fn = GLOBAL_CONST_CLOSURE;
$fn();
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_closure_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/global_const_closure.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $filePath)
                    ->toThrow(TypeError::class, 'GLOBAL_CONST_CLOSURE(): Return value must be of type int')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('accepts valid constant closure and executes cleanly', function () {
            if (PHP_VERSION_ID < 80500) {
                expect(true)->toBeTrue();

                return;
            }

            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

class ConfigConstClosureValid
{
    /**
     * @var callable(positive-int): non-empty-string
     */
    public const Formatter = static function (int $id): string {
        return "user_{$id}";
    };
}

$fn = ConfigConstClosureValid::Formatter;
return $fn(42);
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_const_closure_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/ConfigConstClosureValid.php';
            file_put_contents($filePath, $source);

            try {
                StreamWrapper::register();

                $result = require $filePath;
                expect($result)->toBe('user_42');
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });

        test('guarantees zero line-drift when instrumenting closures inside constants', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

class ConfigClosureDriftTest
{
    /**
     * @var callable(positive-int $code): int
     */
    public const Callback = static function (int $code) {
        $multiplier = 2;
        return $code * $multiplier;
    };
}

$landmark = 'line_18_exact';
PHP;

            $transformed = StreamWrapper::transformSource($source, 'ConfigClosureDriftTest.php');

            $origLines = explode("\n", str_replace("\r\n", "\n", $source));
            $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

            expect(\count($transLines))->toBe(\count($origLines));

            $origIndex = array_search('$landmark = \'line_18_exact\';', array_map('trim', $origLines), true);
            $transIndex = array_search('$landmark = \'line_18_exact\';', array_map('trim', $transLines), true);

            expect($transIndex)->toBe($origIndex)
                ->and($origIndex)->toBe(17)
            ;
        });

        test('eliminates return check when closure return is statically proven valid', function () {
            $source = <<<'PHP'
<?php

class StaticallyProvenClosureConst
{
    /**
     * @var callable(): non-empty-string
     */
    public const Generator = static function () {
        return 'proven_valid_string';
    };
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'StaticallyProvenClosureConst.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn');
        });
    });
});
