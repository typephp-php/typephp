<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

test('error factory creates an ErrorMessage value object', function () {
    $err = ErrorFactory::createError('Test argument error message');

    expect($err)->toBeInstanceOf(ErrorMessage::class)
        ->and($err->getMessage())->toBe('Test argument error message')
    ;
});

test('prepareException converts standard TypeError into ExactTypeError with caller trace details', function () {
    $err = new TypeError('Test parameter failure');
    $prepared = ErrorFactory::prepareException($err);

    expect($prepared)->toBeInstanceOf(TypeError::class);
});

test('prepareException mutates file and line directly when provided', function () {
    $err = new TypeError('Test parameter failure');
    $prepared = ErrorFactory::prepareException($err, 100, '/app/Services/UserService.php');

    expect($prepared->getFile())->toBe('/app/Services/UserService.php')
        ->and($prepared->getLine())->toBe(100)
    ;
});

test('sanitizeMessage strips CallableWrapper and replaces with target file and line', function () {
    $rawMessage = 'Test error, called in /project/src/Internal/Wrapper/CallableWrapper.php on line 123';
    $err = new TypeError($rawMessage);

    $prepared = ErrorFactory::prepareException($err, 42, '/app/Action.php');

    expect($prepared->getMessage())->toBe('Test error, called in /app/Action.php on line 42');
});

test('sanitizeMessage strips CallableWrapper when target file and line are omitted', function () {
    $rawMessage = 'Test error, called in /project/src/Internal/Wrapper/CallableWrapper.php on line 123';
    $err = new TypeError($rawMessage);

    $prepared = ErrorFactory::prepareException($err);

    expect($prepared->getMessage())->toBe('Test error');
});

test('sanitizeMessage strips RunCommand CLI prefix', function () {
    $rawMessage = 'TypePHP\Internal\Cli\RunCommand::Target error message';
    $err = new TypeError($rawMessage);

    $prepared = ErrorFactory::prepareException($err);

    expect($prepared->getMessage())->toBe('Target error message');
});

test('createError converts null given to none returned for return contracts', function () {
    $rawMsg = 'findUser(): Return value must be of type string, null given';
    $err = ErrorFactory::createError($rawMsg);

    expect($err->getMessage())->toBe('findUser(): Return value must be of type string, none returned');
});

test('does not corrupt user string literals containing the word given in return error messages', function () {
    $rawMsg = "getOrderDiscount(): Return value must be of type positive-int, string 'discount given to customer' given";
    $err = ErrorFactory::createError($rawMsg);

    expect($err->getMessage())->toBe("getOrderDiscount(): Return value must be of type positive-int, string 'discount given to customer' returned");
});

describe('Call-Site File & Line Attribution Integrity', function () {
    beforeEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    afterEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    test('never combines caller file with callee line when an explicit line is provided for a property error', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_err_atomic_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;

        $calleeFile = $tempDir . '/CalleePropWriter.php';
        $callerFile = $tempDir . '/CallerEntry.php';

        file_put_contents($calleeFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Diagnostic\AtomicFixture;

use TypeError;
use TypePHP\Internal\Diagnostic\ErrorFactory;

function makePreparedPropError(): TypeError
{
    $e = new TypeError('Property App\Model::$score must be of type positive-int, negative int (-5) given');

    return ErrorFactory::prepareException($e, 14);
}
PHP);

        file_put_contents($callerFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Diagnostic\AtomicFixture;

use TypeError;

function invokeCallee(): TypeError
{
    return makePreparedPropError();
}
PHP);

        try {
            require_once $calleeFile;
            require_once $callerFile;

            $prepared = \TypePHP\Tests\Internal\Diagnostic\AtomicFixture\invokeCallee();

            expect(str_replace('\\', '/', $prepared->getFile()))
                ->toBe(str_replace('\\', '/', $calleeFile))
                ->and($prepared->getLine())->toBe(14)
            ;
        } finally {
            @unlink($calleeFile);
            @unlink($callerFile);
            @rmdir($tempDir);
        }
    });

    test('points cross-file magic __get() property violation to exact caller file and caller line', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_err_magic_cross_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;

        $modelFile = $tempDir . '/MagicBaseModel.php';
        $consumerFile = $tempDir . '/MagicConsumer.php';

        $padding = str_repeat("\n", 25);
        file_put_contents($modelFile, "<?php\n\ndeclare(strict_types=1);\n\nnamespace TypePHP\\Tests\\Internal\\Diagnostic\\MagicCrossFixture;\n\nclass MagicBaseModel\n{\n    /** @var array<string, mixed> */\n    protected array \$attributes = ['is_active' => true];\n{$padding}    public function __get(string \$key): mixed\n    {\n        return \$this->attributes[\$key] ?? null;\n    }\n}\n\n/**\n * @property object \$is_active\n */\nclass MagicUser extends MagicBaseModel\n{\n}\n");

        file_put_contents($consumerFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Diagnostic\MagicCrossFixture;

function readActiveFlag(MagicUser $user): mixed
{
    return $user->is_active;
}
PHP);

        try {
            Config::set([
                'magic_properties' => ['write' => true, 'read' => true],
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            require_once $modelFile;
            require_once $consumerFile;

            $user = new TypePHP\Tests\Internal\Diagnostic\MagicCrossFixture\MagicUser();

            try {
                \TypePHP\Tests\Internal\Diagnostic\MagicCrossFixture\readActiveFlag($user);
                $this->fail('Expected TypePHP TypeError to be thrown');
            } catch (TypePHPTypeError $e) {
                expect(str_replace('\\', '/', $e->getFile()))
                    ->toBe(str_replace('\\', '/', $consumerFile))
                    ->and($e->getLine())->toBe(9)
                ;
            }
        } finally {
            @unlink($modelFile);
            @unlink($consumerFile);
            @rmdir($tempDir);
        }
    });

    test('points same-file magic __get() property violation to the caller read line, not the __get return line', function () {
        $baseTemp = sys_get_temp_dir() . '/typephp_err_magic_same_' . uniqid();
        mkdir($baseTemp, 0777, true);
        $tempDir = realpath($baseTemp) !== false ? realpath($baseTemp) : $baseTemp;

        $sameFile = $tempDir . '/SameFileMagic.php';

        file_put_contents($sameFile, <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Diagnostic\MagicSameFixture;

/**
 * @property object $is_active
 */
class SameFileMagicUser
{
    /** @var array<string, mixed> */
    protected array $attributes = ['is_active' => false];

    public function __get(string $key): mixed
    {
        return $this->attributes[$key] ?? null;
    }
}

function readSameFileActive(SameFileMagicUser $user): mixed
{
    return $user->is_active;
}
PHP);

        try {
            Config::set([
                'magic_properties' => ['write' => true, 'read' => true],
                'include' => [str_replace('\\', '/', $tempDir) . '/**'],
                'exclude' => ['vendor/**'],
            ]);
            StreamWrapper::register();

            require_once $sameFile;

            $user = new TypePHP\Tests\Internal\Diagnostic\MagicSameFixture\SameFileMagicUser();

            try {
                \TypePHP\Tests\Internal\Diagnostic\MagicSameFixture\readSameFileActive($user);
                $this->fail('Expected TypePHP TypeError to be thrown');
            } catch (TypePHPTypeError $e) {
                expect(str_replace('\\', '/', $e->getFile()))
                    ->toBe(str_replace('\\', '/', $sameFile))
                    ->and($e->getLine())->toBe(23)
                ;
            }
        } finally {
            @unlink($sameFile);
            @rmdir($tempDir);
        }
    });
});
