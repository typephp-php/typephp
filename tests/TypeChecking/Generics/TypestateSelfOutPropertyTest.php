<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\TypePHP;

/**
 * Fixture: State-machine where property $state is bound to template T
 *
 * @template T of 'unauthenticated'|'authenticated'
 */
class FixtureTypestateAuthSession
{
    /**
     * @var T
     */
    public string $state = 'unauthenticated';

    /**
     * @var string|null
     */
    public ?string $username = null;

    /**
     * @param non-empty-string $username
     * @param non-empty-string $password
     *
     * @self-out FixtureTypestateAuthSession<'authenticated'>
     *
     * @return static
     */
    public function login(string $username, string $password): static
    {
        $this->username = $username;
        $this->state = 'authenticated';

        return $this;
    }

    /**
     * @self-out FixtureTypestateAuthSession<'unauthenticated'>
     *
     * @return static
     */
    public function logout(): static
    {
        $this->username = null;
        $this->state = 'unauthenticated';

        return $this;
    }

    /**
     * @return non-empty-string
     */
    public function getDashboard(): string
    {
        return "Welcome, {$this->username}!";
    }
}

describe('Typestate Properties with @self-out Transitions', function () {
    test('transitions property typed with template T during logout() (User Test 3)', function () {
        $session = new FixtureTypestateAuthSession();

        $session->login('alice', 'hunter2');
        expect($session->state)->toBe('authenticated')
            ->and(TypePHP::getGenericType($session))->toBe("'authenticated'")
        ;

        $session->logout();

        expect($session->state)->toBe('unauthenticated')
            ->and(TypePHP::getGenericType($session))->toBe("'unauthenticated'")
        ;
    });

    test('supports multi-step workflow transitioning property back and forth (User Test 6)', function () {
        $session = new FixtureTypestateAuthSession();

        $session->login('bob', 'secret');
        expect(TypePHP::getGenericType($session))->toBe("'authenticated'");

        $session->logout();
        expect(TypePHP::getGenericType($session))->toBe("'unauthenticated'");

        $session->login('charlie', 'password');
        expect(TypePHP::getGenericType($session))->toBe("'authenticated'");
    });

    test('allows login() transition when instance is explicitly pre-bound to initial state', function () {
        /** @var FixtureTypestateAuthSession<'unauthenticated'> $session */
        $session = new FixtureTypestateAuthSession();

        expect(TypePHP::getGenericType($session))->toBe("'unauthenticated'");

        $session->login('alice', 'hunter2');

        expect($session->state)->toBe('authenticated')
            ->and(TypePHP::getGenericType($session))->toBe("'authenticated'")
        ;
    });

    test('still protects against direct external property mutations', function () {
        $session = new FixtureTypestateAuthSession();
        $session->login('alice', 'hunter2');

        expect(fn () => $session->state = 'hacked')
            ->toThrow(TypeError::class, 'must be literal')
        ;
    });
});

describe('Typestate Transitions with Cloning & Reference Aliasing', function () {
    test('cloned instance inherits transitioned typestate from original object', function () {
        $session = new FixtureTypestateAuthSession();
        $session->login('alice', 'hunter2');

        expect(TypePHP::getGenericType($session))->toBe("'authenticated'");

        $cloned = clone $session;

        expect(TypePHP::getGenericType($cloned))->toBe("'authenticated'")
            ->and($cloned->state)->toBe('authenticated')
            ->and($cloned->username)->toBe('alice')
        ;
    });

    test('preserves WeakMap state isolation when clone transitions to a different typestate', function () {
        $session = new FixtureTypestateAuthSession();
        $session->login('alice', 'hunter2');

        $cloned = clone $session;
        expect(TypePHP::getGenericType($cloned))->toBe("'authenticated'");

        $cloned->logout();

        expect(TypePHP::getGenericType($cloned))->toBe("'unauthenticated'")
            ->and(TypePHP::getGenericType($session))->toBe("'authenticated'")
            ->and($cloned->state)->toBe('unauthenticated')
            ->and($session->state)->toBe('authenticated')
        ;
    });

    test('enforces inline @var typestate contract when assigning from clone expression', function () {
        $session = new FixtureTypestateAuthSession();
        $session->login('alice', 'hunter2');

        /** @var FixtureTypestateAuthSession<'authenticated'> $authCopy */
        $authCopy = clone $session;
        expect($authCopy->state)->toBe('authenticated');

        $unauthSession = new FixtureTypestateAuthSession();
        $unauthSession->login('bob', 'secret');
        $unauthSession->logout(); 

        expect(function () use ($unauthSession) {
            /** @var FixtureTypestateAuthSession<'authenticated'> $badCopy */
            $badCopy = clone $unauthSession;
        })->toThrow(TypeError::class, "FixtureTypestateAuthSession<invariant 'authenticated'>");
    });

    test('throws TypeError when assigning invalid value to reference aliasing cloned typestate property', function () {
        $session = new FixtureTypestateAuthSession();
        $session->login('alice', 'hunter2');

        /** @var FixtureTypestateAuthSession<'authenticated'> $cloned */
        $cloned = clone $session;

        $ref = &$cloned->state;

        expect(function () use (&$ref) {
            $ref = 'hacked_state';
        })->toThrow(TypeError::class, "must be literal 'authenticated'");

        expect($cloned->state)->toBe('authenticated');
    });

    test('allows valid assignment to reference aliasing cloned typestate property', function () {
        $session = new FixtureTypestateAuthSession();
        $session->login('alice', 'hunter2');

        /** @var FixtureTypestateAuthSession<'authenticated'> $cloned */
        $cloned = clone $session;

        $ref = &$cloned->state;
        $ref = 'authenticated';

        expect($cloned->state)->toBe('authenticated')
            ->and($ref)->toBe('authenticated')
        ;
    });

    test('propagates typestate to reference alias when cloning without explicit @var annotation', function () {
        /** @var FixtureTypestateAuthSession<'authenticated'> $session */
        $session = new FixtureTypestateAuthSession();
        $session->login('alice', 'hunter2');

        $cloned = clone $session;

        $ref = &$cloned->state;

        expect(function () use (&$ref) {
            $ref = 'invalid_state';
        })->toThrow(TypeError::class, "must be literal 'authenticated'");
    });
});