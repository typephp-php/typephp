<?php

declare(strict_types=1);

namespace TypePHP\Tests\Unit;

use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

describe('Unannotated Native Return Type Injection on Non-Final Standalone Classes', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('1. Standalone Non-Final Classes Without @return Contracts (Must NOT Inject checkReturn)', function () {
        test('does not inject checkReturn into unannotated method with native scalar return type', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class StandaloneCounter
{
    private int $count = 0;

    public function count(): int
    {
        return $this->count;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'StandaloneCounter.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->not()->toContain('$__typephpRet')
                ->and($transformed)->toContain('return $this->count;')
            ;
        });

        test('does not inject checkReturn into unannotated methods with nullable or union native return types', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class StandaloneModels
{
    private ?string $name = null;

    private int|string $id = 1;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getId(): int|string
    {
        return $this->id;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'StandaloneModels.php');

            expect($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->not()->toContain('$__typephpRet')
            ;
        });

        test('does not inject checkReturn when standalone method only has @param or summary docblock and native return type', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class StandaloneMath
{
    /**
     * Doubles the input value.
     *
     * @param positive-int $value
     */
    public function doubleValue(int $value): int
    {
        return $value * 2;
    }

    /**
     * Returns the raw value.
     */
    public function echoValue(int $value): int
    {
        return $value;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'StandaloneMath.php');

            expect($transformed)->toContain('RuntimeTypeChecker::setupScope')
                ->and($transformed)->not()->toContain('RuntimeTypeChecker::checkReturn')
                ->and($transformed)->not()->toContain('$__typephpRet')
            ;
        });
    });

    describe('2. Controls: Explicit @return, Inheritance, and Stubs (Must STILL Inject checkReturn)', function () {
        test('injects checkReturn when standalone non-final method has explicit @return docblock', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

class StandaloneAnnotated
{
    /**
     * @return positive-int
     */
    public function getPositive(int $value): int
    {
        return $value;
    }
}
PHP;

            $transformed = StreamWrapper::transformSource($source, 'StandaloneAnnotated.php');

            expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
        });

        test('injects checkReturn on unannotated method with native return type when class extends, implements, or uses a trait', function () {
            $extendsSource = <<<'PHP'
<?php
class ChildCounter extends BaseCounter
{
    private int $count = 0;

    public function count(): int
    {
        return $this->count;
    }
}
PHP;
            expect(StreamWrapper::transformSource($extendsSource, 'ChildCounter.php'))
                ->toContain('RuntimeTypeChecker::checkReturn')
            ;

            $implementsSource = <<<'PHP'
<?php
class ImplCounter implements \Countable
{
    private int $count = 0;

    public function count(): int
    {
        return $this->count;
    }
}
PHP;
            expect(StreamWrapper::transformSource($implementsSource, 'ImplCounter.php'))
                ->toContain('RuntimeTypeChecker::checkReturn')
            ;

            $traitSource = <<<'PHP'
<?php
class TraitCounter
{
    use CounterTrait;

    private int $count = 0;

    public function count(): int
    {
        return $this->count;
    }
}
PHP;
            expect(StreamWrapper::transformSource($traitSource, 'TraitCounter.php'))
                ->toContain('RuntimeTypeChecker::checkReturn')
            ;
        });

        test('injects checkReturn on standalone non-final class when a stub file defines @return for the method', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_issue3_stub_' . uniqid();
            mkdir($tempDir, 0777, true);
            $stubFile = $tempDir . '/StubbedStandalone.stub';

            file_put_contents($stubFile, <<<'PHP'
<?php
namespace App\Issue3Stub;

class StubbedStandalone
{
    /**
     * @return positive-int
     */
    public function getScore(int $score): int
    {
    }
}
PHP);

            try {
                Config::set([
                    'stubs' => [str_replace('\\', '/', $tempDir) . '/**'],
                ]);

                $source = <<<'PHP'
<?php
namespace App\Issue3Stub;

class StubbedStandalone
{
    public function getScore(int $score): int
    {
        return $score;
    }
}
PHP;

                $transformed = StreamWrapper::transformSource($source, 'StubbedStandalone.php');

                expect($transformed)->toContain('RuntimeTypeChecker::checkReturn');
            } finally {
                @unlink($stubFile);
                @rmdir($tempDir);
            }
        });
    });
});
