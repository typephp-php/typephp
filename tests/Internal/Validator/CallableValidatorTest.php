<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Validator\CallableValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

class CallableTestHost
{
    public function getBoundClosure(): Closure
    {
        return fn () => $this;
    }

    public function getStaticClosure(): Closure
    {
        return static fn () => 10;
    }

    public function validInstanceMethod(): string
    {
        return 'ok';
    }

    public static function validStaticMethod(): string
    {
        return 'static_ok';
    }
}

describe('CallableValidator Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
        $this->validator = new CallableValidator();

        $this->callableNode = new CallableTypeNode(new IdentifierTypeNode('callable'), [], new IdentifierTypeNode('void'), []);
        $this->closureNode = new CallableTypeNode(new IdentifierTypeNode('Closure'), [], new IdentifierTypeNode('void'), []);
        $this->staticClosureNode = new CallableTypeNode(new IdentifierTypeNode('static-closure'), [], new IdentifierTypeNode('void'), []);
        $this->pureCallableNode = new CallableTypeNode(new IdentifierTypeNode('pure-callable'), [], new IdentifierTypeNode('void'), []);
        $this->pureClosureNode = new CallableTypeNode(new IdentifierTypeNode('pure-Closure'), [], new IdentifierTypeNode('void'), []);
        $this->staticPureClosureNode = new CallableTypeNode(new IdentifierTypeNode('static-pure-closure'), [], new IdentifierTypeNode('void'), []);
    });

    test('accepts valid closures, strings, invokables, and array callables', function () {
        $host = new CallableTestHost();
        $invokable = new class () {
            public function __invoke(): void
            {
            }
        };

        expect($this->validator->validate(fn () => null, $this->callableNode, 'cb', $this->registry))->toBeNull()
            ->and($this->validator->validate('strlen', $this->callableNode, 'cb', $this->registry))->toBeNull()
            ->and($this->validator->validate($invokable, $this->callableNode, 'cb', $this->registry))->toBeNull()
            ->and($this->validator->validate([$host, 'validInstanceMethod'], $this->callableNode, 'cb', $this->registry))->toBeNull()
            ->and($this->validator->validate([CallableTestHost::class, 'validStaticMethod'], $this->callableNode, 'cb', $this->registry))->toBeNull()
            ->and($this->validator->validate('strlen', $this->pureCallableNode, 'cb', $this->registry))->toBeNull()
        ;
    });

    test('rejects non-callable arrays (e.g. undefined methods)', function () {
        $badArray = [new stdClass(), 'nonExistentMethod'];

        $err = $this->validator->validate($badArray, $this->callableNode, 'callback', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toBe('callback must be of type callable, list (2 items) given')
        ;
    });

    test('rejects non-callable primitives and objects', function () {
        expect($this->validator->validate(12345, $this->callableNode, 'cb', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate('non_existent_function_xyz', $this->callableNode, 'cb', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(new stdClass(), $this->callableNode, 'cb', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate([], $this->callableNode, 'cb', $this->registry))->toBeInstanceOf(ErrorMessage::class)
            ->and($this->validator->validate(null, $this->callableNode, 'cb', $this->registry))->toBeInstanceOf(ErrorMessage::class)
        ;
    });

    test('accepts native Closure instances', function () {
        $fn = fn () => 'hello';

        expect($this->validator->validate($fn, $this->closureNode, 'fn', $this->registry))->toBeNull()
            ->and($this->validator->validate($fn, $this->pureClosureNode, 'fn', $this->registry))->toBeNull()
        ;
    });

    test('rejects non-closure callables even if valid callable (string, array, invokable)', function () {
        $host = new CallableTestHost();
        $invokable = new class () {
            public function __invoke(): void
            {
            }
        };

        $errString = $this->validator->validate('strlen', $this->closureNode, 'fn', $this->registry);
        expect($errString)->toBeInstanceOf(ErrorMessage::class)
            ->and($errString->getMessage())->toBe("fn must be of type Closure, string 'strlen' given")
        ;

        $errArray = $this->validator->validate([$host, 'validInstanceMethod'], $this->closureNode, 'fn', $this->registry);
        expect($errArray)->toBeInstanceOf(ErrorMessage::class)
            ->and($errArray->getMessage())->toBe('fn must be of type Closure, list (2 items) given')
        ;

        $errInvokable = $this->validator->validate($invokable, $this->closureNode, 'fn', $this->registry);
        expect($errInvokable)->toBeInstanceOf(ErrorMessage::class)
            ->and($errInvokable->getMessage())->toContain('must be of type Closure')
        ;
    });

    test('rejects non-callable arrays on Closure constraints', function () {
        $badArray = [new stdClass(), 'nonExistentMethod'];

        $err = $this->validator->validate($badArray, $this->closureNode, 'fn', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toBe('fn must be of type Closure, list (2 items) given')
        ;
    });

    test('accepts genuinely static closures', function () {
        $staticFn = static fn () => 42;
        $host = new CallableTestHost();

        expect($this->validator->validate($staticFn, $this->staticClosureNode, 'fn', $this->registry))->toBeNull()
            ->and($this->validator->validate($host->getStaticClosure(), $this->staticClosureNode, 'fn', $this->registry))->toBeNull()
            ->and($this->validator->validate($staticFn, $this->staticPureClosureNode, 'fn', $this->registry))->toBeNull()
        ;
    });

    test('rejects closures bound to $this instance', function () {
        $host = new CallableTestHost();
        $boundFn = $host->getBoundClosure();

        $err = $this->validator->validate($boundFn, $this->staticClosureNode, 'fn', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toBe('fn must be a static Closure (not bound to $this)')
        ;

        $errPure = $this->validator->validate($boundFn, $this->staticPureClosureNode, 'fn', $this->registry);
        expect($errPure)->toBeInstanceOf(ErrorMessage::class)
            ->and($errPure->getMessage())->toBe('fn must be a static Closure (not bound to $this)')
        ;
    });

    test('rejects non-closure values on static-closure constraints', function () {
        $err = $this->validator->validate('strlen', $this->staticClosureNode, 'fn', $this->registry);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toContain('must be of type Closure')
        ;
    });

    test('formats error without leaking value when isSensitive is true', function () {
        $err = $this->validator->validate('secret_non_callable_payload', $this->callableNode, 'param', $this->registry, isSensitive: true);

        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toBe('param must be of type callable, string given')
            ->and($err->getMessage())->not()->toContain('secret_non_callable_payload')
        ;
    });

    test('routes CallableTypeNode directly through TypeValidatorRegistry', function () {
        expect($this->registry->validate(fn () => null, $this->callableNode, 'test'))->toBeNull();

        $err = $this->registry->validate([new stdClass(), 'missing'], $this->callableNode, 'test');
        expect($err)->toBeInstanceOf(ErrorMessage::class)
            ->and($err->getMessage())->toBe('test must be of type callable, list (2 items) given')
        ;
    });
});
