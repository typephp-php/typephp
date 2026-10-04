<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

use stdClass;
use TypePHP\Exception\TypeError;

interface FixtureWithCount
{
}

final class FixtureCounted implements FixtureWithCount
{
}

final class FixtureUncounted
{
}

class ConditionalMethodVisibilityFinder
{
    /**
     * @return ($command is FixtureWithCount ? list<object> : list<int>)
     */
    private function resultPrivate(object $command): array
    {
        return [new stdClass()];
    }

    /**
     * @return ($command is FixtureWithCount ? list<object> : list<int>)
     */
    private function resultPrivateInts(object $command): array
    {
        return [10, 20];
    }

    /**
     * @return ($command is FixtureWithCount ? list<object> : list<int>)
     */
    protected function resultProtected(object $command): array
    {
        return [new stdClass()];
    }

    /**
     * @return ($command is FixtureWithCount ? list<object> : list<int>)
     */
    protected function resultProtectedInts(object $command): array
    {
        return [10, 20];
    }

    /**
     * @return ($command is FixtureWithCount ? list<object> : list<int>)
     */
    public function resultPublic(object $command): array
    {
        return [new stdClass()];
    }

    /**
     * @return ($command is FixtureWithCount ? list<object> : list<int>)
     */
    public function resultPublicInts(object $command): array
    {
        return [10, 20];
    }

    /**
     * @return ($limit is 1 ? object : list<object>)
     */
    private function slicePrivate(int $limit): object|array
    {
        if ($limit === 1) {
            return new stdClass();
        }

        return [new stdClass(), new stdClass()];
    }

    /**
     * @return ($limit is 1 ? object : list<object>)
     */
    protected function sliceProtected(int $limit): object|array
    {
        if ($limit === 1) {
            return new stdClass();
        }

        return [new stdClass(), new stdClass()];
    }

    /**
     * @param mixed $command
     *
     * @return ($command is FixtureWithCount ? list<object> : list<int>)
     */
    private function resultWithMixedParam(mixed $command): array
    {
        return [new stdClass()];
    }

    public function runPrivateCounted(): array
    {
        return $this->resultPrivate(new FixtureCounted());
    }

    public function runPrivateUncountedValid(): array
    {
        return $this->resultPrivateInts(new FixtureUncounted());
    }

    public function runPrivateUncountedInvalid(): array
    {
        return $this->resultPrivate(new FixtureUncounted());
    }

    public function runPrivateSlice(int $limit): object|array
    {
        return $this->slicePrivate($limit);
    }

    public function runMixedParam(): array
    {
        return $this->resultWithMixedParam(new FixtureCounted());
    }

    public function runProtectedCounted(): array
    {
        return $this->resultProtected(new FixtureCounted());
    }

    public function runProtectedUncountedValid(): array
    {
        return $this->resultProtectedInts(new FixtureUncounted());
    }

    public function runProtectedUncountedInvalid(): array
    {
        return $this->resultProtected(new FixtureUncounted());
    }

    public function runProtectedSlice(int $limit): object|array
    {
        return $this->sliceProtected($limit);
    }
}

/**
 * Standalone function with conditional return and no @param tag
 *
 * @return ($command is FixtureWithCount ? list<object> : list<int>)
 */
function testStandaloneConditionalWithoutParamTag(object $command): array
{
    return [new stdClass()];
}

describe('Conditional Return Types Across Method Visibilities (private, protected, public)', function () {
    describe('Private Methods', function () {
        test('evaluates true branch of parameter conditional on private method (User Bug Report)', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            $result = $finder->runPrivateCounted();

            expect($result)->toBeArray()
                ->and($result[0])->toBeInstanceOf(stdClass::class)
            ;
        });

        test('evaluates false branch of parameter conditional on private method when condition fails', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            expect($finder->runPrivateUncountedValid())->toBe([10, 20]);

            expect(fn () => $finder->runPrivateUncountedInvalid())
                ->toThrow(TypeError::class, 'must be of type int, stdClass returned')
            ;
        });

        test('evaluates literal value condition on private method ($limit is 1 ? object : list<object>)', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            expect($finder->runPrivateSlice(1))->toBeInstanceOf(stdClass::class)
                ->and($finder->runPrivateSlice(5))->toBeArray()
                ->and(\count($finder->runPrivateSlice(5)))->toBe(2)
            ;
        });

        test('evaluates parameter conditional on private method when parameter has @param mixed', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            expect($finder->runMixedParam())->toBeArray()
                ->and($finder->runMixedParam()[0])->toBeInstanceOf(stdClass::class)
            ;
        });
    });

    describe('Protected Methods', function () {
        test('evaluates true branch of parameter conditional on protected method', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            $result = $finder->runProtectedCounted();

            expect($result)->toBeArray()
                ->and($result[0])->toBeInstanceOf(stdClass::class)
            ;
        });

        test('evaluates false branch of parameter conditional on protected method when condition fails', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            expect($finder->runProtectedUncountedValid())->toBe([10, 20]);

            expect(fn () => $finder->runProtectedUncountedInvalid())
                ->toThrow(TypeError::class, 'must be of type int, stdClass returned')
            ;
        });

        test('evaluates literal value condition on protected method ($limit is 1 ? object : list<object>)', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            expect($finder->runProtectedSlice(1))->toBeInstanceOf(stdClass::class)
                ->and($finder->runProtectedSlice(5))->toBeArray()
                ->and(\count($finder->runProtectedSlice(5)))->toBe(2)
            ;
        });
    });

    describe('Public Methods', function () {
        test('evaluates true branch of parameter conditional on public method', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            $result = $finder->resultPublic(new FixtureCounted());

            expect($result)->toBeArray()
                ->and($result[0])->toBeInstanceOf(stdClass::class)
            ;
        });

        test('evaluates false branch of parameter conditional on public method when condition fails', function () {
            $finder = new ConditionalMethodVisibilityFinder();

            expect($finder->resultPublicInts(new FixtureUncounted()))->toBe([10, 20]);

            expect(fn () => $finder->resultPublic(new FixtureUncounted()))
                ->toThrow(TypeError::class, 'must be of type int, stdClass returned')
            ;
        });
    });

    describe('Standalone Functions', function () {
        test('evaluates parameter conditional on standalone functions without @param tag', function () {
            $result = testStandaloneConditionalWithoutParamTag(new FixtureCounted());

            expect($result)->toBeArray()
                ->and($result[0])->toBeInstanceOf(stdClass::class)
            ;
        });
    });
});
