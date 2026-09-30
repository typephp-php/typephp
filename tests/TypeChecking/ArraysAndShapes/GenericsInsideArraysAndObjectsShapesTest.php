<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @template T
 */
class ShapeBoxFixture
{
    /**
     * @var array{value: T, label: non-empty-string}
     */
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
    /**
     * @var array<int, T>
     */
    private array $items = [];

    /**
     * @param T $item
     */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }

    /**
     * @return array<int, T>
     */
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
     * Method-level template inside an array shape
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

/**
 * @template T
 */
class ClassLevelEnvelopeFixture
{
    /**
     * @param stdClass{input: T, output: T} $envelope
     */
    public function __construct(public stdClass $envelope)
    {
    }
}

class MethodLevelObjectTransformerFixture
{
    /**
     * Method-level template inside stdClass shape
     *
     * @template T
     *
     * @param stdClass{input: T, output: T} $wrapped
     *
     * @return stdClass{input: T, output: T}
     */
    public function identity(stdClass $wrapped): stdClass
    {
        return $wrapped;
    }

    /**
     * Method-level template inside pure object shape
     *
     * @template T
     *
     * @param object{input: T, output: T} $wrapped
     *
     * @return object{input: T, output: T}
     */
    public function pureObjectIdentity(object $wrapped): object
    {
        return $wrapped;
    }
}

/**
 * @template T
 */
class ObjectPayloadFixture
{
    /**
     * @param stdClass{data: ShapeBoxFixture<T>, meta: stdClass{timestamp: positive-int}} $wrapper
     */
    public function __construct(public stdClass $wrapper)
    {
    }

    /**
     * @return stdClass{data: ShapeBoxFixture<T>, meta: stdClass{timestamp: positive-int}}
     */
    public function unwrap(): stdClass
    {
        return $this->wrapper;
    }
}

/**
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

/**
 * @template T
 *
 * @param stdClass{input: T, output: T} $wrapped
 *
 * @return stdClass{input: T, output: T}
 */
function testStandaloneObjectShapeIdentity(stdClass $wrapped): stdClass
{
    return $wrapped;
}

/**
 * @template T
 *
 * @param object{input: T, output: T} $wrapped
 *
 * @return object{input: T, output: T}
 */
function testStandalonePureObjectShapeIdentity(object $wrapped): object
{
    return $wrapped;
}

/**
 * @template K of array-key
 * @template V
 *
 * @param stdClass{key: K, value: V} $entry
 *
 * @return stdClass{key: K, value: V}
 */
function testStandaloneMultipleTemplateObjectShape(stdClass $entry): stdClass
{
    return $entry;
}

/**
 * @template T of Animal
 *
 * @param stdClass{pet: T, tag: non-empty-string} $data
 *
 * @return stdClass{pet: T, tag: non-empty-string}
 */
function testStandaloneBoundedObjectShape(stdClass $data): stdClass
{
    return $data;
}

/**
 * @template T
 *
 * @param stdClass{container: object{value: T}, fallback: T} $nested
 *
 * @return T
 */
function testStandaloneNestedObjectShapeTemplates(stdClass $nested): mixed
{
    return $nested->fallback;
}

/**
 * @template T
 *
 * @param object{required: T, optional?: T} $payload
 *
 * @return object{required: T, optional?: T}
 */
function testStandaloneOptionalFieldObjectShape(object $payload): object
{
    return $payload;
}

/**
 * @template T
 *
 * @param stdClass{result: T, code: positive-int} $payload
 * @param mixed $toReturn
 *
 * @return T
 */
function testStandaloneReturnInferredFromObjectShape(stdClass $payload, mixed $toReturn): mixed
{
    return $toReturn;
}

describe('Generics Inside Array Shapes and Object Shapes', function () {
    describe('Group 1: Class Template T inside Array Shape Field (Box<T>)', function () {
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

    describe('Group 2: Nested Generics (list<T>) inside Array Shape (Bag<T>)', function () {
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

    describe('Group 3: Two Templates (K, V) inside One Array Shape (Map<K, V>)', function () {
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

    describe('Group 4: Array Shape as Generic Type Argument to Collection (Collection<array{...}>)', function () {
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

    describe('Group 5: Array Shape Field Contains a Generic Object (Payload<T>)', function () {
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

    describe('Group 6: Method-Level Template Inside Array Shape (Transformer::identity)', function () {
        test('accepts method call when template T matches on all array shape fields', function () {
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

    describe('Group 7: Class-Level @template T in stdClass Shapes (ClassLevelEnvelope<T>)', function () {
        test('accepts valid stdClass matching ClassLevelEnvelope<int>', function () {
            $obj = new stdClass();
            $obj->input = 42;
            $obj->output = 99;

            /** @var ClassLevelEnvelopeFixture<int> $env */
            $env = new ClassLevelEnvelopeFixture($obj);

            expect($env->envelope->input)->toBe(42)
                ->and($env->envelope->output)->toBe(99)
            ;
        });

        test('rejects stdClass when output violates pre-bound T=int', function () {
            $obj = new stdClass();
            $obj->input = 42;
            $obj->output = 'string';

            expect(function () use ($obj) {
                /** @var ClassLevelEnvelopeFixture<int> $env */
                $env = new ClassLevelEnvelopeFixture($obj);
            })->toThrow(TypeError::class, '->output must be of type int');
        });

        test('rejects stdClass when input violates pre-bound T=int', function () {
            $obj = new stdClass();
            $obj->input = 'string';
            $obj->output = 'string';

            expect(function () use ($obj) {
                /** @var ClassLevelEnvelopeFixture<int> $env */
                $env = new ClassLevelEnvelopeFixture($obj);
            })->toThrow(TypeError::class, '->input must be of type int');
        });
    });

    describe('Group 8: Method-Level @template T in stdClass Shapes (MethodLevelObjectTransformer::identity)', function () {
        test('accepts matching integer types on both object shape fields', function () {
            $t = new MethodLevelObjectTransformerFixture();
            $obj = new stdClass();
            $obj->input = 42;
            $obj->output = 99;

            $result = $t->identity($obj);

            expect($result->input)->toBe(42)
                ->and($result->output)->toBe(99)
            ;
        });

        test('accepts matching string types on both object shape fields', function () {
            $t = new MethodLevelObjectTransformerFixture();
            $obj = new stdClass();
            $obj->input = 'a';
            $obj->output = 'b';

            $result = $t->identity($obj);

            expect($result->input)->toBe('a')
                ->and($result->output)->toBe('b')
            ;
        });

        test('rejects method call when output does not match inferred template type from input', function () {
            $t = new MethodLevelObjectTransformerFixture();
            $obj = new stdClass();
            $obj->input = 42;
            $obj->output = 'string';

            expect(fn () => $t->identity($obj))
                ->toThrow(TypeError::class, '->output must be of type int')
            ;
        });

        test('rejects method call when input and output types are reverse mismatched', function () {
            $t = new MethodLevelObjectTransformerFixture();
            $obj = new stdClass();
            $obj->input = 'string';
            $obj->output = 42;

            expect(fn () => $t->identity($obj))
                ->toThrow(TypeError::class, '->output must be of type string')
            ;
        });
    });

    describe('Group 9: Method-Level @template T in Pure Object Shapes (object{input: T, output: T})', function () {
        test('accepts pure object shape with matching properties on custom object', function () {
            $t = new MethodLevelObjectTransformerFixture();
            $user = new class () {
                public int $input = 10;
                public int $output = 20;
            };

            $result = $t->pureObjectIdentity($user);
            expect($result->input)->toBe(10)
                ->and($result->output)->toBe(20)
            ;
        });

        test('rejects pure object shape when properties have mismatched template types', function () {
            $t = new MethodLevelObjectTransformerFixture();
            $badUser = new class () {
                public int $input = 10;
                public string $output = 'mismatch';
            };

            expect(fn () => $t->pureObjectIdentity($badUser))
                ->toThrow(TypeError::class, '->output must be of type int')
            ;
        });
    });

    describe('Group 10: Object Shape Containing Another Generic Object (ObjectPayload<T>)', function () {
        test('accepts ObjectPayload<int> with matching Box<int> inside object shape', function () {
            /** @var ShapeBoxFixture<int> $inner */
            $inner = new ShapeBoxFixture(['value' => 42, 'label' => 'answer']);

            $wrapper = new stdClass();
            $wrapper->data = $inner;
            $wrapper->meta = (object)['timestamp' => 1700000000];

            /** @var ObjectPayloadFixture<int> $payload */
            $payload = new ObjectPayloadFixture($wrapper);

            expect($payload->unwrap()->data->get()['value'])->toBe(42);
        });

        test('rejects ObjectPayload<int> with mismatched Box<string> inside object shape', function () {
            /** @var ShapeBoxFixture<string> $inner */
            $inner = new ShapeBoxFixture(['value' => 'hello', 'label' => 'greeting']);

            $wrapper = new stdClass();
            $wrapper->data = $inner;
            $wrapper->meta = (object)['timestamp' => 1700000000];

            expect(function () use ($wrapper) {
                /** @var ObjectPayloadFixture<int> $payload */
                $payload = new ObjectPayloadFixture($wrapper);
            })->toThrow(TypeError::class, '->data expects');
        });
    });

    describe('Group 11: Standalone Functions with Method/Function-Level Templates in Shapes', function () {
        test('infers T from first field and validates subsequent fields in array shape', function () {
            $valid = testStandaloneShapeIdentity(['input' => 'hello', 'output' => 'world', 'meta' => 'ok']);
            expect($valid['input'])->toBe('hello')
                ->and($valid['output'])->toBe('world')
            ;

            expect(fn () => testStandaloneShapeIdentity(['input' => 'hello', 'output' => 12345, 'meta' => 'ok']))
                ->toThrow(TypeError::class, "['output'] must be of type string")
            ;
        });

        test('infers T from first property and validates subsequent properties in stdClass shape', function () {
            $obj = new stdClass();
            $obj->input = 'alpha';
            $obj->output = 'beta';

            $result = testStandaloneObjectShapeIdentity($obj);
            expect($result->input)->toBe('alpha')
                ->and($result->output)->toBe('beta')
            ;

            $badObj = new stdClass();
            $badObj->input = 'alpha';
            $badObj->output = 12345;

            expect(fn () => testStandaloneObjectShapeIdentity($badObj))
                ->toThrow(TypeError::class, '->output must be of type string')
            ;
        });

        test('infers T on pure object{input: T, output: T} shapes for arbitrary objects', function () {
            $user = new class () {
                public int $input = 10;
                public int $output = 20;
            };

            $result = testStandalonePureObjectShapeIdentity($user);
            expect($result->input)->toBe(10);

            $badUser = new class () {
                public int $input = 10;
                public string $output = 'mismatch';
            };

            expect(fn () => testStandalonePureObjectShapeIdentity($badUser))
                ->toThrow(TypeError::class, '->output must be of type int')
            ;
        });
    });

    describe('Group 12: Standalone Functions with Multiple Templates (K, V) in Shapes', function () {
        test('infers both K and V independently from array shape fields', function () {
            $result = testStandaloneMultipleTemplateShape(['key' => 'user_id', 'value' => 100]);
            expect($result['key'])->toBe('user_id')
                ->and($result['value'])->toBe(100)
            ;
        });

        test('infers both K and V independently from object shape properties', function () {
            $entry = new stdClass();
            $entry->key = 'user_id';
            $entry->value = 100;

            $result = testStandaloneMultipleTemplateObjectShape($entry);
            expect($result->key)->toBe('user_id')
                ->and($result->value)->toBe(100)
            ;
        });

        test('rejects key violating array-key bound on K in object shape', function () {
            $badEntry = new stdClass();
            $badEntry->key = new stdClass();
            $badEntry->value = 100;

            expect(fn () => testStandaloneMultipleTemplateObjectShape($badEntry))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Group 13: Standalone Functions with Bounded Templates (@template T of Animal) in Shapes', function () {
        test('accepts Dog matching Animal bound in array shape field', function () {
            $dog = new Dog();
            $result = testStandaloneBoundedShape(['pet' => $dog, 'tag' => 'rescued']);

            expect($result['pet'])->toBe($dog);
        });

        test('rejects Car violating Animal bound in array shape field', function () {
            expect(fn () => testStandaloneBoundedShape(['pet' => new Car(), 'tag' => 'fast']))
                ->toThrow(TypeError::class)
            ;
        });

        test('accepts Dog matching Animal bound in object shape property', function () {
            $dog = new Dog();
            $obj = new stdClass();
            $obj->pet = $dog;
            $obj->tag = 'good_boy';

            $result = testStandaloneBoundedObjectShape($obj);
            expect($result->pet)->toBe($dog);
        });

        test('rejects Car violating Animal bound in object shape property', function () {
            $obj = new stdClass();
            $obj->pet = new Car();
            $obj->tag = 'speed';

            expect(fn () => testStandaloneBoundedObjectShape($obj))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Group 14: Standalone Functions with Nested Shapes Containing Templates', function () {
        test('infers T from deep array shape field and validates against outer field', function () {
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

        test('infers T from deep object shape property and validates against outer property', function () {
            $obj = new stdClass();
            $obj->container = (object)['value' => 100];
            $obj->fallback = 200;

            expect(testStandaloneNestedObjectShapeTemplates($obj))->toBe(200);

            $badObj = new stdClass();
            $badObj->container = (object)['value' => 100];
            $badObj->fallback = 'not_an_int';

            expect(fn () => testStandaloneNestedObjectShapeTemplates($badObj))
                ->toThrow(TypeError::class, '->fallback must be of type int')
            ;
        });
    });

    describe('Group 15: Standalone Functions with Unsealed Shapes and Generics', function () {
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

    describe('Group 16: Standalone Functions with Optional Shape Fields Containing Templates', function () {
        test('accepts call when optional array shape field matching T is omitted', function () {
            $res = testStandaloneOptionalFieldShape(['required' => 'alice']);
            expect($res)->toBe(['required' => 'alice']);
        });

        test('rejects call when optional array shape field violates inferred T', function () {
            expect(fn () => testStandaloneOptionalFieldShape(['required' => 'alice', 'optional' => 12345]))
                ->toThrow(TypeError::class, "['optional'] must be of type string")
            ;
        });

        test('accepts call when optional object shape property matching T is omitted', function () {
            $obj = (object)['required' => 'alice'];
            $res = testStandaloneOptionalFieldObjectShape($obj);
            expect($res->required)->toBe('alice');
        });

        test('rejects call when optional object shape property violates inferred T', function () {
            $badObj = (object)['required' => 'alice', 'optional' => 12345];
            expect(fn () => testStandaloneOptionalFieldObjectShape($badObj))
                ->toThrow(TypeError::class, '->optional must be of type string')
            ;
        });
    });

    describe('Group 17: Return Types Inferred from Shape Parameter Fields/Properties', function () {
        test('validates return value against template inferred from array shape argument', function () {
            $valid = testStandaloneReturnInferredFromShape(['result' => 'success_payload', 'code' => 200], 'success_payload');
            expect($valid)->toBe('success_payload');

            expect(fn () => testStandaloneReturnInferredFromShape(['result' => 'success_payload', 'code' => 200], -999))
                ->toThrow(TypeError::class, 'Return value must be of type string')
            ;
        });

        test('validates return value against template inferred from object shape argument', function () {
            $payload = new stdClass();
            $payload->result = 'success_token';
            $payload->code = 200;

            $result = testStandaloneReturnInferredFromObjectShape($payload, 'success_token');
            expect($result)->toBe('success_token');

            expect(fn () => testStandaloneReturnInferredFromObjectShape($payload, -999))
                ->toThrow(TypeError::class, 'Return value must be of type string')
            ;
        });
    });
});