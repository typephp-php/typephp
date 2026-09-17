<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

class CrmEventLogFixture
{
}

class EmailEventLogFixture
{
}

class SevdeskEventLogFixture
{
}

class OtherEntityFixture
{
}

/**
 * Base generic repository with class-level template T
 *
 * @template T of object
 */
abstract class DynamoRepositoryFixture
{
}

/**
 * Trait providing reflection helpers with method-level template T
 */
trait HasReflectionClassHelperFixture
{
    /**
     * Method template T must shadow the class template T!
     *
     * @template T of object
     *
     * @param null|class-string<T>|T $objectOrClass
     *
     * @return class-string<T>|T|null
     */
    public function getCachedReflectionClass(null|string|object $objectOrClass = null): null|string|object
    {
        return $objectOrClass;
    }

    /**
     * Same shadowing test when parameter uses `object` alongside class-string<T>
     *
     * @template T of object
     *
     * @param null|class-string<T>|object $objectOrClass
     *
     * @return class-string<T>|object|null
     */
    public function getSingleClassReflectionAttribute(null|string|object $objectOrClass = null): null|string|object
    {
        return $this->getCachedReflectionClass($objectOrClass);
    }
}

/**
 * Concrete repository binding class-level T to a union of entities
 *
 * @extends DynamoRepositoryFixture<CrmEventLogFixture|EmailEventLogFixture|SevdeskEventLogFixture>
 */
class EventLogRepositoryFixture extends DynamoRepositoryFixture
{
    use HasReflectionClassHelperFixture;

    public function testForwardThis(): mixed
    {
        return $this->getCachedReflectionClass($this);
    }

    public function testForwardThisViaAttributeHelper(): mixed
    {
        return $this->getSingleClassReflectionAttribute($this);
    }
}

/**
 * Direct class (without traits) where method template T shadows class template T
 *
 * @template T of object
 */
class DirectClassTemplateShadowFixture
{
    /**
     * @template T of object
     *
     * @param T $item
     *
     * @return T
     */
    public function inspectItem(object $item): object
    {
        return $item;
    }
}

describe('Method-Level @template T Shadowing Class-Level @template T', function () {
    test('method-level template T in trait shadows class-level template T when passing $this', function () {
        $repo = new EventLogRepositoryFixture();

        $result = $repo->testForwardThis();

        expect($result)->toBe($repo);
    });

    test('method-level template T handles calling with arbitrary entity class-string or instances', function () {
        $repo = new EventLogRepositoryFixture();

        $entityClass = OtherEntityFixture::class;
        $resultClass = $repo->getCachedReflectionClass($entityClass);
        expect($resultClass)->toBe($entityClass);

        $otherInstance = new OtherEntityFixture();
        $resultInstance = $repo->getCachedReflectionClass($otherInstance);
        expect($resultInstance)->toBe($otherInstance);
    });

    test('method-level template T in trait forwards $this through attribute helper', function () {
        $repo = new EventLogRepositoryFixture();

        $result = $repo->testForwardThisViaAttributeHelper();

        expect($result)->toBe($repo);
    });

    test('direct class method template T shadows class-level template T', function () {
        /** @var DirectClassTemplateShadowFixture<CrmEventLogFixture> $fixture */
        $fixture = new DirectClassTemplateShadowFixture();

        $other = new OtherEntityFixture();
        $result = $fixture->inspectItem($other);

        expect($result)->toBe($other);
    });
});
