<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use stdClass;
use TypePHP\Exception\TypeError;
use TypePHP\TypePHP;

/**
 * @template TState of 'unauthenticated'|'authenticated' = 'unauthenticated'
 */
class DefaultTypestateSession
{
    /**
     * @self-out self<'authenticated'>
     */
    public function login(): void
    {
    }

    /**
     * @self-out self<'unauthenticated'>
     */
    public function logout(): void
    {
    }
}

final class DefaultTypestateSubSession extends DefaultTypestateSession
{
}

final class DefaultTemplateCustomPayload
{
}

/**
 * @template T of object = stdClass
 */
final class DefaultObjectGenericBox
{
}

/**
 * @param DefaultTypestateSession<'authenticated'> $session
 */
function accessDefaultTypestateDashboard(DefaultTypestateSession $session): string
{
    return 'granted';
}

/**
 * @param DefaultTypestateSession<'unauthenticated'> $session
 */
function accessDefaultTypestateLoginPage(DefaultTypestateSession $session): string
{
    return 'login';
}

/**
 * @return DefaultTypestateSession<'authenticated'>
 */
function returnForgedDefaultSession(): DefaultTypestateSession
{
    return new DefaultTypestateSession();
}

/**
 * @param DefaultObjectGenericBox<DefaultTemplateCustomPayload> $box
 */
function acceptCustomPayloadDefaultBox(DefaultObjectGenericBox $box): bool
{
    return true;
}

/**
 * @param DefaultObjectGenericBox<stdClass> $box
 */
function acceptStdClassDefaultBox(DefaultObjectGenericBox $box): bool
{
    return true;
}

describe('Default Generic Template Enforcement at Call Boundaries', function () {
    test('rejects passing unannotated new Session() (default unauthenticated) into authenticated-only function', function () {
        $session = new DefaultTypestateSession();

        expect(TypePHP::getGenericType($session))->toBe("'unauthenticated'");

        expect(fn () => accessDefaultTypestateDashboard($session))
            ->toThrow(TypeError::class, "DefaultTypestateSession<invariant 'authenticated'>, but " . DefaultTypestateSession::class . "<'unauthenticated'> was given")
        ;

        expect(accessDefaultTypestateLoginPage($session))->toBe('login');
    });

    test('enforces full typestate lifecycle without @var (blocked -> login -> allowed -> logout -> blocked)', function () {
        $session = new DefaultTypestateSession();

        expect(fn () => accessDefaultTypestateDashboard($session))
            ->toThrow(TypeError::class)
        ;

        $session->login();
        expect(TypePHP::getGenericType($session))->toBe("'authenticated'")
            ->and(accessDefaultTypestateDashboard($session))->toBe('granted')
        ;

        $session->logout();
        expect(TypePHP::getGenericType($session))->toBe("'unauthenticated'");

        expect(fn () => accessDefaultTypestateDashboard($session))
            ->toThrow(TypeError::class)
        ;
    });

    test('still allows explicit specialization via inline @var at instantiation', function () {
        /** @var DefaultTypestateSession<'authenticated'> $session */
        $session = new DefaultTypestateSession();

        expect(TypePHP::getGenericType($session))->toBe("'authenticated'")
            ->and(accessDefaultTypestateDashboard($session))->toBe('granted')
        ;
    });

    test('rejects returning bare new Session() when @return requires authenticated state', function () {
        expect(fn () => returnForgedDefaultSession())
            ->toThrow(TypeError::class, "'authenticated'")
        ;
    });

    test('enforces default object template (= stdClass) at parameter boundary while allowing explicit @var specialization', function () {
        $freshBox = new DefaultObjectGenericBox();

        expect(fn () => acceptCustomPayloadDefaultBox($freshBox))
            ->toThrow(TypeError::class, DefaultTemplateCustomPayload::class)
        ;

        expect(acceptStdClassDefaultBox($freshBox))->toBeTrue();

        /** @var DefaultObjectGenericBox<DefaultTemplateCustomPayload> $specializedBox */
        $specializedBox = new DefaultObjectGenericBox();
        expect(acceptCustomPayloadDefaultBox($specializedBox))->toBeTrue();
    });

    test('enforces inherited default generic template on subclasses without @var', function () {
        $subSession = new DefaultTypestateSubSession();

        expect(fn () => accessDefaultTypestateDashboard($subSession))
            ->toThrow(TypeError::class, "'unauthenticated'")
        ;

        $subSession->login();
        expect(accessDefaultTypestateDashboard($subSession))->toBe('granted');
    });
});
