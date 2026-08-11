<?php

declare(strict_types=1);

/**
 * @param array{id: int, tags: list<string|int>}|null $payload
 */
function testDeepUnionError(mixed $payload): bool
{
    return true;
}

/**
 * @return array{name: string, args: list<string|int|false>}|null
 */
function testAttributeCompilerSim(): ?array
{
    return [
        'name' => 'Field',
        'args' => ['column', 'property', new \stdClass()],
    ];
}

/**
 * @param object{id: positive-int, profile: object{name: non-empty-string}}|null $user
 */
function testDeepObjectShapeUnion(mixed $user): bool
{
    return true;
}

/**
 * @param (array{type: 'A', data: array{score: positive-int}} | array{type: 'B', data: array{code: non-empty-string}})|null $discriminated
 */
function testDiscriminatedUnionDeepError(mixed $discriminated): bool
{
    return true;
}

class PropertyUnionFixture
{
    /**
     * @var array{config: array{enabled: bool}}|null
     */
    public ?array $settings = null;
}

describe('Union Deep Error Bubbling', function () {
    test('surfaces deep array shape error instead of generic union error', function () {
        expect(fn() => testDeepUnionError(['id' => 10, 'tags' => ['hello', false]]))
            ->toThrow(\TypeError::class, "Argument \$payload['tags'][1] must be of type (string | int)");
    });

    test('surfaces deep return shape error mimicking AttributeEntityCompiler', function () {
        expect(fn() => testAttributeCompilerSim())
            ->toThrow(\TypeError::class, "Return value['args'][2] must be of type (string | int | false)");
    });
    
    test('surfaces missing key error from array shape inside union', function () {
        expect(fn() => testDeepUnionError(['id' => 10]))
            ->toThrow(\TypeError::class, "Argument \$payload is missing required key 'tags'");
    });

    test('surfaces deep object shape error inside union', function () {
        $user = new \stdClass();
        $user->id = 10;
        $user->profile = new \stdClass();
        $user->profile->name = ''; 

        expect(fn() => testDeepObjectShapeUnion($user))
            ->toThrow(\TypeError::class, "Argument \$user->profile->name must be of type non-empty-string");
    });

    test('surfaces deep error in nested discriminated union shape', function () {
        $payload = [
            'type' => 'A',
            'data' => ['score' => -5],
        ];

        expect(fn() => testDiscriminatedUnionDeepError($payload))
            ->toThrow(\TypeError::class, "['score'] must be of type positive-int");
    });

    test('surfaces deep error on class property with union shape', function () {
        $fixture = new PropertyUnionFixture();

        expect(fn() => $fixture->settings = ['config' => ['enabled' => 'not_a_bool']])
            ->toThrow(\TypeError::class, "Property PropertyUnionFixture::\$settings['config']['enabled'] must be of type bool");
    });

    test('falls back gracefully to generic union error when no deep branch matches structure', function () {
        expect(fn() => testDeepUnionError('string_instead_of_array'))
            ->toThrow(\TypeError::class, "must be of type (array{id: int, tags: list<(string | int)>} | null)");
    });
});