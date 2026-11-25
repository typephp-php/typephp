<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;
use TypePHP\TypePHP;

class Priority1NullableParamFixture
{
    /**
     * Native nullable parameter with non-nullable DocBlock contract
     * Un-annotated native return type so native PHP does not permit null
     *
     * @param positive-int $id
     *
     * @return positive-int
     */
    public function returnPositiveId(?int $id = null)
    {
        return $id;
    }

    /**
     * Native union with null and non-nullable DocBlock return
     *
     * @param positive-int $id
     *
     * @return positive-int
     */
    public function returnUnionPositiveId(int|null $id = null)
    {
        return $id;
    }
}

class Priority1StaticByRefHolder
{
    public static array $matches = [];

    public static array $list = [3, 1, 2];

    public static string $token = 'initial';

    public static function customByRefMutator(string &$ref): void
    {
        $ref = 'mutated';
    }

    public static function executePregMatch(): int|false
    {
        return preg_match('/([a-z]+)/', 'hello', self::$matches);
    }

    public static function executeSort(): bool
    {
        return sort(self::$list);
    }

    public static function executeCustomByRef(): void
    {
        self::customByRefMutator(self::$token);
    }

    public static function getArrowRef(): \Closure
    {
        return fn &(): string => self::$token;
    }
}

describe('Priority 1 Bugs: Type Safety & Call Boundary Soundness', function () {
    beforeEach(function () {
        Config::reset();
        TypePHP::clearViolations();
    });

    afterEach(function () {
        TypePHP::clearViolations();
        Config::reset();
    });

    describe('Bug 1: collectTrustedParamTypes vs respect_native_nullability & Report Mode', function () {
        test('rejects null return when native parameter is ?int=null but DocBlock specifies positive-int', function () {
            $fixture = new Priority1NullableParamFixture();

            expect(fn () => $fixture->returnPositiveId(null))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });

        test('rejects null return when native parameter is int|null=null but DocBlock specifies positive-int', function () {
            $fixture = new Priority1NullableParamFixture();

            expect(fn () => $fixture->returnUnionPositiveId(null))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });

        test('records both parameter and return violations in report mode when unmutated parameter is returned', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_p1_report_' . uniqid();
            mkdir($tempDir, 0777, true);
            $filePath = $tempDir . '/report_return.php';

            $code = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Priority1;

class ReportReturnFixture
{
    /**
     * @param positive-int $id
     * @return positive-int
     */
    public function echoPositive(int $id)
    {
        return $id;
    }
}
PHP;
            file_put_contents($filePath, $code);

            try {
                Config::set(['on_violation' => 'report']);
                StreamWrapper::register();

                require_once $filePath;

                $fixture = new \App\Priority1\ReportReturnFixture();
                $result = $fixture->echoPositive(-10);

                expect($result)->toBe(-10);

                $violations = TypePHP::getViolations();
                $kinds = array_map(static fn ($v): string => $v->kind, $violations);

                expect($kinds)->toContain('parameter')
                    ->and($kinds)->toContain('return')
                ;
            } finally {
                @unlink($filePath);
                @rmdir($tempDir);
            }
        });
    });

    describe('Bug 2: StaticPropertyFetch in By-Reference Arguments & By-Ref Arrow Functions', function () {
        test('passes static property directly to preg_match without fatal error or StaticCall rewriting', function () {
            Priority1StaticByRefHolder::$matches = [];

            $result = Priority1StaticByRefHolder::executePregMatch();

            expect($result)->toBe(1)
                ->and(Priority1StaticByRefHolder::$matches[0])->toBe('hello')
            ;
        });

        test('passes static property directly to sort() by-reference without fatal error', function () {
            Priority1StaticByRefHolder::$list = [3, 1, 2];

            $result = Priority1StaticByRefHolder::executeSort();

            expect($result)->toBeTrue()
                ->and(Priority1StaticByRefHolder::$list)->toBe([1, 2, 3])
            ;
        });

        test('passes static property to user-defined by-reference method without fatal error', function () {
            Priority1StaticByRefHolder::$token = 'initial';

            Priority1StaticByRefHolder::executeCustomByRef();

            expect(Priority1StaticByRefHolder::$token)->toBe('mutated');
        });

        test('tracks by-reference returns in PHP 8.2+ arrow functions (fn &())', function () {
            Priority1StaticByRefHolder::$token = 'valid';

            $arrow = Priority1StaticByRefHolder::getArrowRef();
            $ref = &$arrow();
            $ref = 'updated';

            expect(Priority1StaticByRefHolder::$token)->toBe('updated');
        });
    });
});
