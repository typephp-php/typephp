<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @template T
 */
class ShapeBoxFixture
{
    /** @var array{value: T, label: non-empty-string} */
    private array $data;

    /**
     * @param array{value: T, label: non-empty-string} $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @return array{value: T, label: non-empty-string}
     */
    public function get(): array
    {
        return $this->data;
    }
}

/**
 * @template T
 */
class ShapeBagFixture
{
    /**
     * @param array{items: list<T>, count: positive-int} $data
     */
    public function __construct(public array $data)
    {
    }

    /**
     * @return array{items: list<T>, count: positive-int}
     */
    public function all(): array
    {
        return $this->data;
    }
}

/**
 * @template K
 * @template V
 */
class ShapeMapFixture
{
    /**
     * @param array{key: K, value: V, meta: array{source: non-empty-string}} $entry
     */
    public function __construct(public array $entry)
    {
    }

    /**
     * @return array{key: K, value: V, meta: array{source: non-empty-string}}
     */
    public function entry(): array
    {
        return $this->entry;
    }
}

/**
 * @template T
 */
class ShapeCollectionFixture
{
    /** @var array<int, T> */
    private array $items = [];

    /** @param T $item */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }

    /** @return array<int, T> */
    public function all(): array
    {
        return $this->items;
    }
}

/**
 * @template T
 */
class ShapePayloadFixture
{
    /**
     * @param array{data: ShapeBoxFixture<T>, meta: array{timestamp: positive-int}} $wrapper
     */
    public function __construct(public array $wrapper)
    {
    }

    /**
     * @return array{data: ShapeBoxFixture<T>, meta: array{timestamp: positive-int}}
     */
    public function unwrap(): array
    {
        return $this->wrapper;
    }
}

class ShapeTransformerFixture
{
    /**
     * Method-level template inside a shape
     *
     * @template T
     *
     * @param array{input: T, output: T, meta: non-empty-string} $pair
     *
     * @return array{input: T, output: T, meta: non-empty-string}
     */
    public function identity(array $pair): array
    {
        return $pair;
    }
}

// =========================================================================
// Standalone Function Fixtures (Edge Cases)
// =========================================================================

/**
 * Standalone function with method/function-level template inside a shape
 *
 * @template T
 *
 * @param array{input: T, output: T, meta: non-empty-string} $pair
 *
 * @return array{input: T, output: T, meta: non-empty-string}
 */
function testStandaloneShapeIdentity(array $pair): array
{
    return $pair;
}

/**
 * Standalone function with multiple templates inside a shape
 *
 * @template K of array-key
 * @template V
 *
 * @param array{key: K, value: V} $entry
 *
 * @return array{key: K, value: V}
 */
function testStandaloneMultipleTemplateShape(array $entry): array
{
    return $entry;
}

/**
 * Standalone function with bounded template inside an array shape
 *
 * @template T of Animal
 *
 * @param array{pet: T, tag: non-empty-string} $data
 *
 * @return array{pet: T, tag: non-empty-string}
 */
function testStandaloneBoundedShape(array $data): array
{
    return $data;
}

/**
 * Standalone function with nested array shapes containing templates
 *
 * @template T
 *
 * @param array{container: array{value: T}, fallback: T} $nested
 *
 * @return T
 */
function testStandaloneNestedShapeTemplates(array $nested): mixed
{
    return $nested['fallback'];
}

/**
 * Standalone function with unsealed shape containing template
 *
 * @template T
 *
 * @param array{primary: T, ...<string, T>} $records
 *
 * @return array{primary: T, ...<string, T>}
 */
function testStandaloneUnsealedShapeTemplate(array $records): array
{
    return $records;
}

/**
 * Standalone function with optional shape field containing template
 *
 * @template T
 *
 * @param array{required: T, optional?: T} $payload
 *
 * @return array{required: T, optional?: T}
 */
function testStandaloneOptionalFieldShape(array $payload): array
{
    return $payload;
}

/**
 * Standalone function where return type is inferred from a shape parameter field
 *
 * @template T
 *
 * @param array{result: T, code: positive-int} $payload
 * @param mixed $toReturn
 *
 * @return T
 */
function testStandaloneReturnInferredFromShape(array $payload, mixed $toReturn): mixed
{
    return $toReturn;
}

describe('Generics Inside Array Shapes & Tuples', function () {
    beforeEach(function () {
        Config::reset();
        Config::set([
            'inline_vars' => [
                'generics' => true,
                'callables' => true,
                'scalars' => true,
                'arrays' => true,
                'objects' => true,
            ],
        ]);
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Group 1: Class Template T inside Shape Field (Box<T>)', function () {
        test('accepts valid shape matching Box<int>', function () {
            /** @var ShapeBoxFixture<int> $box */
            $box = new ShapeBoxFixture(['value' => 42, 'label' => 'answer']);
            expect($box->get()['value'])->toBe(42)
                ->and($box->get()['label'])->toBe('answer')
            ;
        });

        test('rejects value violating bound template Box<int>', function () {
            expect(function () {
                /** @var ShapeBoxFixture<int> $box */
                $box = new ShapeBoxFixture(['value' => 'not an int', 'label' => 'x']);
            })->toThrow(TypeError::class, "['value'] must be of type int");
        });

        test('rejects shape with invalid label constraint', function () {
            expect(function () {
                /** @var ShapeBoxFixture<int> $box */
                $box = new ShapeBoxFixture(['value' => 42, 'label' => '']);
            })->toThrow(TypeError::class, "['label'] must be of type non-empty-string");
        });

        test('rejects shape missing required key', function () {
            expect(function () {
                /** @var ShapeBoxFixture<int> $box */
                $box = new ShapeBoxFixture(['value' => 42]);
            })->toThrow(TypeError::class, "missing required key 'label'");
        });
    });

    describe('Group 2: Nested Generics (list<T>) inside Shape (Bag<T>)', function () {
        test('accepts valid list<int> and positive-int count', function () {
            /** @var ShapeBagFixture<int> $bag */
            $bag = new ShapeBagFixture(['items' => [1, 2, 3], 'count' => 3]);
            expect($bag->all()['items'])->toBe([1, 2, 3])
                ->and($bag->all()['count'])->toBe(3)
            ;
        });

        test('rejects invalid element inside list<T>', function () {
            expect(function () {
                /** @var ShapeBagFixture<int> $bag */
                $bag = new ShapeBagFixture(['items' => [1, 'two', 3], 'count' => 3]);
            })->toThrow(TypeError::class, "['items'][1] must be of type int");
        });

        test('rejects non-positive count', function () {
            expect(function () {
                /** @var ShapeBagFixture<int> $bag */
                $bag = new ShapeBagFixture(['items' => [1, 2], 'count' => -1]);
            })->toThrow(TypeError::class, "['count'] must be of type positive-int");
        });
    });

    describe('Group 3: Two Templates (K, V) inside One Shape (Map<K, V>)', function () {
        test('accepts valid multi-template shape', function () {
            /** @var ShapeMapFixture<string, int> $map */
            $map = new ShapeMapFixture([
                'key' => 'age',
                'value' => 30,
                'meta' => ['source' => 'api'],
            ]);
            expect($map->entry()['key'])->toBe('age')
                ->and($map->entry()['value'])->toBe(30)
            ;
        });

        test('rejects K when type does not match template', function () {
            expect(function () {
                /** @var ShapeMapFixture<string, int> $map */
                $map = new ShapeMapFixture([
                    'key' => 42,
                    'value' => 30,
                    'meta' => ['source' => 'api'],
                ]);
            })->toThrow(TypeError::class, "['key'] must be of type string");
        });

        test('rejects V when type does not match template', function () {
            expect(function () {
                /** @var ShapeMapFixture<string, int> $map */
                $map = new ShapeMapFixture([
                    'key' => 'age',
                    'value' => 'thirty',
                    'meta' => ['source' => 'api'],
                ]);
            })->toThrow(TypeError::class, "['value'] must be of type int");
        });

        test('rejects invalid nested meta field in shape', function () {
            expect(function () {
                /** @var ShapeMapFixture<string, int> $map */
                $map = new ShapeMapFixture([
                    'key' => 'age',
                    'value' => 30,
                    'meta' => ['source' => ''],
                ]);
            })->toThrow(TypeError::class, "['meta']['source'] must be of type non-empty-string");
        });
    });

    describe('Group 4: Shape as Generic Type Argument to Collection (Collection<array{...}>)', function () {
        test('accepts valid shape elements added to collection', function () {
            /** @var ShapeCollectionFixture<array{id: positive-int, name: non-empty-string}> $users */
            $users = new ShapeCollectionFixture();
            $users->add(['id' => 1, 'name' => 'Alice']);
            $users->add(['id' => 2, 'name' => 'Bob']);

            expect($users->all())->toHaveCount(2);
        });

        test('rejects element with negative id added to shape collection', function () {
            /** @var ShapeCollectionFixture<array{id: positive-int, name: non-empty-string}> $users */
            $users = new ShapeCollectionFixture();

            expect(fn () => $users->add(['id' => -1, 'name' => 'Alice']))
                ->toThrow(TypeError::class, "['id'] must be of type positive-int")
            ;
        });

        test('rejects element with empty name added to shape collection', function () {
            /** @var ShapeCollectionFixture<array{id: positive-int, name: non-empty-string}> $users */
            $users = new ShapeCollectionFixture();

            expect(fn () => $users->add(['id' => 1, 'name' => '']))
                ->toThrow(TypeError::class, "['name'] must be of type non-empty-string")
            ;
        });

        test('rejects element missing required key in shape collection', function () {
            /** @var ShapeCollectionFixture<array{id: positive-int, name: non-empty-string}> $users */
            $users = new ShapeCollectionFixture();

            expect(fn () => $users->add(['id' => 1]))
                ->toThrow(TypeError::class, "missing required key 'name'")
            ;
        });
    });

    describe('Group 5: Shape Field Contains a Generic Object (Payload<T>)', function () {
        test('accepts Payload<int> with matching Box<int> inside shape', function () {
            /** @var ShapeBoxFixture<int> $inner */
            $inner = new ShapeBoxFixture(['value' => 42, 'label' => 'answer']);

            /** @var ShapePayloadFixture<int> $payload */
            $payload = new ShapePayloadFixture([
                'data' => $inner,
                'meta' => ['timestamp' => 1700000000],
            ]);

            expect($payload->unwrap()['data']->get()['value'])->toBe(42);
        });

        test('rejects Payload<int> with mismatched Box<string> inside shape', function () {
            /** @var ShapeBoxFixture<string> $inner */
            $inner = new ShapeBoxFixture(['value' => 'hello', 'label' => 'greeting']);

            expect(function () use ($inner) {
                /** @var ShapePayloadFixture<int> $payload */
                $payload = new ShapePayloadFixture([
                    'data' => $inner,
                    'meta' => ['timestamp' => 1700000000],
                ]);
            })->toThrow(TypeError::class, "['data'] expects");
        });

        test('rejects negative timestamp in payload meta', function () {
            /** @var ShapeBoxFixture<int> $inner */
            $inner = new ShapeBoxFixture(['value' => 42, 'label' => 'answer']);

            expect(function () use ($inner) {
                /** @var ShapePayloadFixture<int> $payload */
                $payload = new ShapePayloadFixture([
                    'data' => $inner,
                    'meta' => ['timestamp' => -1],
                ]);
            })->toThrow(TypeError::class, "['meta']['timestamp'] must be of type positive-int");
        });
    });

    describe('Group 6: Method-Level Template Inside Shape (Transformer::identity)', function () {
        test('accepts method call when template T matches on all shape fields', function () {
            $t = new ShapeTransformerFixture();
            $result = $t->identity(['input' => 42, 'output' => 99, 'meta' => 'test']);

            expect($result)->toBe(['input' => 42, 'output' => 99, 'meta' => 'test']);
        });

        test('rejects method call when fields have mismatched types for template T', function () {
            $t = new ShapeTransformerFixture();

            expect(fn () => $t->identity(['input' => 42, 'output' => 'string', 'meta' => 'test']))
                ->toThrow(TypeError::class, "['output'] must be of type int")
            ;
        });

        test('rejects method call with empty meta', function () {
            $t = new ShapeTransformerFixture();

            expect(fn () => $t->identity(['input' => 42, 'output' => 42, 'meta' => '']))
                ->toThrow(TypeError::class, "['meta'] must be of type non-empty-string")
            ;
        });
    });

    describe('Group 7: Standalone Functions with Method/Function-Level Templates in Shapes', function () {
        test('infers T from first field and validates subsequent fields in standalone function', function () {
            $valid = testStandaloneShapeIdentity(['input' => 'hello', 'output' => 'world', 'meta' => 'ok']);
            expect($valid['input'])->toBe('hello')
                ->and($valid['output'])->toBe('world')
            ;

            expect(fn () => testStandaloneShapeIdentity(['input' => 'hello', 'output' => 12345, 'meta' => 'ok']))
                ->toThrow(TypeError::class, "['output'] must be of type string")
            ;
        });
    });

    describe('Group 8: Standalone Functions with Multiple Templates (K, V) in Shapes', function () {
        test('infers both K and V independently from shape fields', function () {
            $result = testStandaloneMultipleTemplateShape(['key' => 'user_id', 'value' => 100]);
            expect($result['key'])->toBe('user_id')
                ->and($result['value'])->toBe(100)
            ;

            $resultIntKey = testStandaloneMultipleTemplateShape(['key' => 1, 'value' => 'Admin']);
            expect($resultIntKey['key'])->toBe(1)
                ->and($resultIntKey['value'])->toBe('Admin')
            ;
        });

        test('rejects key violating array-key bound on K', function () {
            expect(fn () => testStandaloneMultipleTemplateShape(['key' => new \stdClass(), 'value' => 100]))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Group 9: Standalone Functions with Bounded Templates (@template T of Animal) in Shapes', function () {
        test('accepts Dog matching Animal bound in shape field', function () {
            $dog = new Dog();
            $result = testStandaloneBoundedShape(['pet' => $dog, 'tag' => 'rescued']);

            expect($result['pet'])->toBe($dog);
        });

        test('rejects Car violating Animal bound in shape field', function () {
            expect(fn () => testStandaloneBoundedShape(['pet' => new Car(), 'tag' => 'fast']))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Group 10: Standalone Functions with Nested Shapes Containing Templates', function () {
        test('infers T from deep shape field and validates against outer field', function () {
            $valid = testStandaloneNestedShapeTemplates([
                'container' => ['value' => 100],
                'fallback' => 200,
            ]);
            expect($valid)->toBe(200);

            expect(fn () => testStandaloneNestedShapeTemplates([
                'container' => ['value' => 100],
                'fallback' => 'not_an_int',
            ]))->toThrow(TypeError::class, "['fallback'] must be of type int");
        });
    });

    describe('Group 11: Standalone Functions with Unsealed Shapes and Generics', function () {
        test('infers T from primary key and validates dynamic extra keys against T', function () {
            $valid = testStandaloneUnsealedShapeTemplate([
                'primary' => 10,
                'secondary' => 20,
                'tertiary' => 30,
            ]);
            expect($valid['primary'])->toBe(10)
                ->and($valid['tertiary'])->toBe(30)
            ;

            expect(fn () => testStandaloneUnsealedShapeTemplate([
                'primary' => 10,
                'secondary' => 'invalid_string',
            ]))->toThrow(TypeError::class, "['secondary'] must be of type int");
        });
    });

    describe('Group 12: Standalone Functions with Optional Shape Fields Containing Templates', function () {
        test('accepts call when optional field matching T is omitted', function () {
            $res = testStandaloneOptionalFieldShape(['required' => 'alice']);
            expect($res)->toBe(['required' => 'alice']);
        });

        test('accepts call when optional field matching T is provided with valid type', function () {
            $res = testStandaloneOptionalFieldShape(['required' => 'alice', 'optional' => 'bob']);
            expect($res['optional'])->toBe('bob');
        });

        test('rejects call when optional field violates inferred T', function () {
            expect(fn () => testStandaloneOptionalFieldShape(['required' => 'alice', 'optional' => 12345]))
                ->toThrow(TypeError::class, "['optional'] must be of type string")
            ;
        });
    });

    describe('Group 13: Return Types Inferred from Shape Parameter Fields', function () {
        test('validates return value against template inferred from shape argument', function () {
            $valid = testStandaloneReturnInferredFromShape(['result' => 'success_payload', 'code' => 200], 'success_payload');
            expect($valid)->toBe('success_payload');

            expect(fn () => testStandaloneReturnInferredFromShape(['result' => 'success_payload', 'code' => 200], -999))
                ->toThrow(TypeError::class, 'Return value must be of type string')
            ;
        });
    });
});