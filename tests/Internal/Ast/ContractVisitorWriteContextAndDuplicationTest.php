<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;

describe('ContractVisitor Write-Context & Target Duplication', function () {
    describe('1. Foreach & By-Ref ArrayItem Write Context with StaticPropertyFetch', function () {
        test('does not wrap foreach valueVar, keyVar, destructured target, or array-dim static property in checkStaticProperty', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

class ForeachStaticTargetFixture
{
    public static int $current = 0;

    public static string $lastKey = '';

    public static int $lastVal = 0;

    /**
     * @var array<string, int>
     */
    public static array $map = [];

    public static int $pairSecond = 0;

    public static function run(): void
    {
        foreach ([10, 20] as self::$current) {
        }

        foreach (['a' => 1, 'b' => 2] as self::$lastKey => self::$lastVal) {
        }

        foreach ([[1, 30], [2, 40]] as [$first, self::$pairSecond]) {
        }

        foreach ([50, 60] as self::$map['latest']) {
        }
    }
}

ForeachStaticTargetFixture::run();

return [
    'current' => ForeachStaticTargetFixture::$current,
    'lastKey' => ForeachStaticTargetFixture::$lastKey,
    'lastVal' => ForeachStaticTargetFixture::$lastVal,
    'pairSecond' => ForeachStaticTargetFixture::$pairSecond,
    'latest' => ForeachStaticTargetFixture::$map['latest'],
];
PHP;

            $transformed = StreamWrapper::transformSource($source, 'foreach_static_target.php');

            expect($transformed)->not()->toContain("checkStaticProperty(self::class, 'current'")
                ->and($transformed)->not()->toContain("checkStaticProperty(self::class, 'lastKey'")
                ->and($transformed)->not()->toContain("checkStaticProperty(self::class, 'lastVal'")
                ->and($transformed)->not()->toContain("checkStaticProperty(self::class, 'pairSecond'")
                ->and($transformed)->not()->toContain("checkStaticProperty(self::class, 'map'")
            ;

            $tempDir = sys_get_temp_dir() . '/typephp_foreach_static_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/run_foreach_static.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();
                $result = require $scriptPath;

                expect($result)->toBe([
                    'current' => 20,
                    'lastKey' => 'b',
                    'lastVal' => 2,
                    'pairSecond' => 40,
                    'latest' => 60,
                ]);
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });

        test('does not wrap by-reference ArrayItem static property (&Class::$prop) in checkStaticProperty', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

class ByRefArrayStaticTargetFixture
{
    public static int $counter = 10;

    public static function mutateViaArrayRef(): int
    {
        $refs = [&self::$counter];
        $refs[0] = 99;

        return self::$counter;
    }
}

return ByRefArrayStaticTargetFixture::mutateViaArrayRef();
PHP;

            $transformed = StreamWrapper::transformSource($source, 'byref_array_static.php');

            expect($transformed)->toContain('[&self::$counter]')
                ->and($transformed)->not()->toContain('&\TypePHP\Internal\RuntimeTypeChecker::checkStaticProperty')
            ;

            $tempDir = sys_get_temp_dir() . '/typephp_byref_static_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/run_byref_static.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();
                $result = require $scriptPath;

                expect($result)->toBe(99);
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });

    describe('2. Side-Effecting Target Single-Evaluation (Assign, AssignOp, IncDec)', function () {
        test('evaluates impure property receiver once on direct assignment, compound assignment, and increment', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

class TargetBox
{
    /**
     * @var positive-int
     */
    public int $score = 10;

    /**
     * @var list<positive-int>
     */
    public array $items = [1, 2, 3];
}

class TargetBoxFactory
{
    public int $calls = 0;

    public TargetBox $box;

    public function __construct()
    {
        $this->box = new TargetBox();
    }

    public function next(): TargetBox
    {
        $this->calls++;

        return $this->box;
    }
}

$factory = new TargetBoxFactory();

$factory->next()->score = 20;
$callsAfterAssign = $factory->calls;

$factory->next()->score += 10;
$callsAfterAssignOp = $factory->calls;

$factory->next()->score++;
$callsAfterInc = $factory->calls;

return [
    'score' => $factory->box->score,
    'callsAfterAssign' => $callsAfterAssign,
    'callsAfterAssignOp' => $callsAfterAssignOp,
    'callsAfterInc' => $callsAfterInc,
];
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_target_once_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/run_target_once.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();
                $result = require $scriptPath;

                expect($result)->toBe([
                    'score' => 31,
                    'callsAfterAssign' => 1,
                    'callsAfterAssignOp' => 2,
                    'callsAfterInc' => 3,
                ]);
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });

        test('evaluates impure array index expression once on compound assignment and property dimension assignment', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

class DimTargetBox
{
    /**
     * @var list<positive-int>
     */
    public array $items = [10, 20, 30];
}

/** @var list<positive-int> $list */
$list = [10, 20, 30];
$idx = 0;
$list[$idx++] += 5;
$idxAfterListCompound = $idx;

$box = new DimTargetBox();
$boxIdx = 0;
$box->items[$boxIdx++] = 99;
$idxAfterPropDim = $boxIdx;

return [
    'list' => $list,
    'idxAfterListCompound' => $idxAfterListCompound,
    'boxItems' => $box->items,
    'idxAfterPropDim' => $idxAfterPropDim,
];
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_dim_once_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/run_dim_once.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();
                $result = require $scriptPath;

                expect($result)->toBe([
                    'list' => [15, 20, 30],
                    'idxAfterListCompound' => 1,
                    'boxItems' => [99, 20, 30],
                    'idxAfterPropDim' => 1,
                ]);
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });

        test('evaluates dynamic static property receiver once and still enforces contract violations', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

class DynamicStaticCounter
{
    /**
     * @var positive-int
     */
    public static int $count = 10;
}

$getCalls = 0;
$getClass = static function () use (&$getCalls): string {
    $getCalls++;

    return DynamicStaticCounter::class;
};

($getClass())::$count += 5;
$callsAfterValid = $getCalls;

try {
    ($getClass())::$count -= 100;
    $threw = false;
} catch (\TypePHP\Exception\TypeError $e) {
    $threw = true;
}

return [
    'count' => DynamicStaticCounter::$count,
    'callsAfterValid' => $callsAfterValid,
    'callsAfterInvalid' => $getCalls,
    'threw' => $threw,
];
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_dyn_static_once_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/run_dyn_static_once.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();
                $result = require $scriptPath;

                expect($result)->toBe([
                    'count' => 15,
                    'callsAfterValid' => 1,
                    'callsAfterInvalid' => 2,
                    'threw' => true,
                ]);
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });

        test('throws TypeError when hoisted impure property assignment violates @var contract', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

class InvalidTargetBox
{
    /**
     * @var positive-int
     */
    public int $score = 10;
}

$calls = 0;
$getBox = static function () use (&$calls): InvalidTargetBox {
    $calls++;

    return new InvalidTargetBox();
};

$getBox()->score = -5;
PHP;

            $tempDir = sys_get_temp_dir() . '/typephp_hoist_err_' . uniqid();
            mkdir($tempDir, 0777, true);
            $scriptPath = $tempDir . '/run_hoist_err.php';
            file_put_contents($scriptPath, $source);

            try {
                StreamWrapper::register();

                expect(fn () => require $scriptPath)
                    ->toThrow(TypeError::class, 'positive-int')
                ;
            } finally {
                @unlink($scriptPath);
                @rmdir($tempDir);
            }
        });
    });
});
