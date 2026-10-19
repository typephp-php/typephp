<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast;

use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

describe('Unannotated Method Return Check Injection (TDD)', function () {
    describe('1. Standalone Classes Without Inheritance (Zero Injections)', function () {
        test('does NOT inject checkReturn or trailing return into standalone class method with no docblocks (The Bug)', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class Dog
{
    public function bark()
    {
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'Dog.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->not()->toContain('$__typephpRet')
                ->and($transformed)->not()->toContain('return')
            ;
        });

        test('does NOT inject checkReturn into final standalone class method with no docblock and existing return', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

final class Cat
{
    public function meow()
    {
        return 'meow';
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'Cat.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->toContain("return 'meow';")
            ;
        });

        test('does NOT inject checkReturn into private method with no docblock', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class SecretHolder
{
    private function getInternalKey()
    {
        return 'secret';
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'SecretHolder.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn');
        });

        test('injects param checks but does NOT inject return checks when standalone method only has @param docblock', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class MathOperation
{
    /**
     * @param positive-int $count
     */
    public function setRepeat(int $count)
    {
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'MathOperation.php');

            expect($transformed)->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->not()->toContain('$__typephpRet')
            ;
        });

        test('does NOT inject checkReturn when method has @return mixed', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class MixedReturnClass
{
    /**
     * @return mixed
     */
    public function getValue()
    {
        return 42;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'MixedReturnClass.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn');
        });
    });

    describe('2. Standalone Classes With Explicit Return Contracts (Must Inject)', function () {
        beforeEach(function () {
            Config::set(['eliminate_statically_proven_checks' => false]);
        });

        afterEach(function () {
            Config::reset();
        });

        test('injects checkReturn when standalone method explicitly declares @return docblock', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class Dog
{
    /**
     * @return non-empty-string
     */
    public function bark(): string
    {
        return 'woof';
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'DogWithDoc.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->toContain('$__typephpRet')
            ;
        });

        test('injects checkReturn when standalone method declares @phpstan-return or @psalm-return', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class ToolingReturnClass
{
    /**
     * @phpstan-return positive-int
     */
    public function getId(): int
    {
        return 10;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'ToolingReturnClass.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
        });

        test('injects checkReturn when method declares $this return contract', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class FluentClass
{
    /**
     * @return $this
     */
    public function setOption()
    {
        return $this;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'FluentClass.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
        });
    });

    describe('3. Classes With Inheritance, Interfaces, Traits, or Templates (LSP Must Inject)', function () {
        test('injects checkReturn into unannotated method when class extends another class (LSP preservation)', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class SubDog extends BaseAnimal
{
    public function bark()
    {
        return 'woof';
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'SubDog.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
        });

        test('injects checkReturn into unannotated method when class implements an interface (LSP preservation)', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class InterfaceDog implements BarkableInterface
{
    public function bark()
    {
        return 'woof';
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'InterfaceDog.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
        });

        test('injects checkReturn into unannotated method when class uses a trait', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class TraitDog
{
    use BarkLoggerTrait;

    public function bark()
    {
        return 'woof';
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'TraitDog.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
        });

        test('injects checkReturn into unannotated method when class has a class-level docblock (templates/magic methods)', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

/**
 * @template T
 */
class GenericDog
{
    public function bark()
    {
        return 'woof';
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'GenericDog.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
        });
    });
});
