<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

use stdClass;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;

/**
 * Class-scoped recursive TreeNode alias
 *
 * @phpstan-type TreeNode array{value: int, children: list<TreeNode>}
 *
 * @psalm-type JsonValue int|float|string|bool|null|list<JsonValue>|array<string, JsonValue>
 */
final class RecursiveTreeService
{
    /**
     * @param TreeNode $tree
     */
    public function processTree(array $tree): bool
    {
        return true;
    }

    /**
     * @return TreeNode
     */
    public function echoTree(array $tree): array
    {
        return $tree;
    }

    /**
     * @param JsonValue $payload
     */
    public function acceptJson(mixed $payload): mixed
    {
        return $payload;
    }

    public function assignInlineTree(mixed $raw): array
    {
        /** @var TreeNode $local */
        $local = $raw;

        return $local;
    }
}

/**
 * Mutually recursive aliases (NodeA <-> NodeB)
 *
 * @phpstan-type MenuLeaf array{label: non-empty-string, href: non-empty-string}
 * @phpstan-type MenuSection array{title: non-empty-string, items: list<MenuLeaf|MenuGroup>}
 * @phpstan-type MenuGroup array{group: non-empty-string, sections: list<MenuSection>}
 */
final class RecursiveMenuBuilder
{
    /**
     * @param MenuGroup $menu
     */
    public function build(array $menu): bool
    {
        return true;
    }
}

/**
 * Importing a recursive alias from another class
 *
 * @phpstan-import-type TreeNode from RecursiveTreeService as ImportedTree
 */
final class ImportedRecursiveTreeConsumer
{
    /**
     * @param ImportedTree $tree
     */
    public function consume(array $tree): bool
    {
        return true;
    }
}

/**
 * @phpstan-type SelfLoop SelfLoop
 */
final class DirectCircularAliasFixture
{
    /**
     * @param SelfLoop $val
     */
    public function accept(mixed $val): mixed
    {
        return $val;
    }
}

/**
 * Detached docblock must NOT leak as a file-scoped alias to standalone functions.
 *
 * @phpstan-type DetachedTree array{value: int, children: list<DetachedTree>}
 */

/**
 * @param DetachedTree $tree
 */
function standaloneDetachedAliasFn(array $tree): bool
{
    return true;
}

describe('Recursive Type Aliases (@phpstan-type / @psalm-type)', function () {
    beforeEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    afterEach(function () {
        Config::reset();
        StreamWrapper::reset();
    });

    describe('1. Class-Scoped Recursive TreeNode', function () {
        test('accepts shallow and deeply nested valid recursive trees on parameters and returns', function () {
            $service = new RecursiveTreeService();

            $shallow = ['value' => 1, 'children' => []];
            expect($service->processTree($shallow))->toBeTrue()
                ->and($service->echoTree($shallow))->toBe($shallow)
            ;

            $deep = [
                'value' => 1,
                'children' => [
                    ['value' => 2, 'children' => []],
                    [
                        'value' => 3,
                        'children' => [
                            [
                                'value' => 4,
                                'children' => [
                                    ['value' => 5, 'children' => []],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            expect($service->processTree($deep))->toBeTrue()
                ->and($service->echoTree($deep))->toBe($deep)
            ;
        });

        test('rejects invalid deeply nested node with full path in error message', function () {
            $service = new RecursiveTreeService();

            $invalidDeep = [
                'value' => 1,
                'children' => [
                    ['value' => 2, 'children' => []],
                    [
                        'value' => 3,
                        'children' => [
                            ['value' => 'I am a string', 'children' => []],
                        ],
                    ],
                ],
            ];

            expect(fn () => $service->processTree($invalidDeep))
                ->toThrow(TypeError::class, "\$tree['children'][1]['children'][0]['value'] must be of type int")
            ;

            expect(fn () => $service->echoTree($invalidDeep))
                ->toThrow(TypeError::class, "Return value['children'][1]['children'][0]['value'] must be of type int")
            ;
        });

        test('rejects missing required key on nested recursive child node', function () {
            $service = new RecursiveTreeService();

            $missingKey = [
                'value' => 1,
                'children' => [
                    ['value' => 2],
                ],
            ];

            expect(fn () => $service->processTree($missingKey))
                ->toThrow(TypeError::class, "is missing required key 'children'")
            ;
        });
    });

    describe('2. Recursive Union Alias (JsonValue)', function () {
        test('validates arbitrary nested JSON scalar, list, and map structures', function () {
            $service = new RecursiveTreeService();

            $validJson = [
                'id' => 42,
                'ratio' => 3.14,
                'active' => true,
                'deletedAt' => null,
                'tags' => ['php', 'runtime', [1, 2, ['nested' => false]]],
                'meta' => [
                    'nested' => [
                        'deep' => ['ok' => 'yes'],
                    ],
                ],
            ];

            expect($service->acceptJson($validJson))->toBe($validJson)
                ->and($service->acceptJson('plain string'))->toBe('plain string')
                ->and($service->acceptJson(null))->toBeNull()
            ;

            $invalidJson = [
                'meta' => [
                    'items' => [
                        ['bad' => new stdClass()],
                    ],
                ],
            ];

            expect(fn () => $service->acceptJson($invalidJson))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('3. Mutual Recursion & Imported Recursive Aliases', function () {
        test('validates mutually recursive aliases (MenuGroup <-> MenuSection)', function () {
            $builder = new RecursiveMenuBuilder();

            $validMenu = [
                'group' => 'Admin',
                'sections' => [
                    [
                        'title' => 'Users',
                        'items' => [
                            ['label' => 'List', 'href' => '/users'],
                            [
                                'group' => 'Roles',
                                'sections' => [
                                    [
                                        'title' => 'Permissions',
                                        'items' => [
                                            ['label' => 'Matrix', 'href' => '/roles/matrix'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            expect($builder->build($validMenu))->toBeTrue();

            $invalidMenu = $validMenu;
            $invalidMenu['sections'][0]['items'][1]['sections'][0]['items'][0]['label'] = '';

            expect(fn () => $builder->build($invalidMenu))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });

        test('validates recursive alias imported across classes via @phpstan-import-type', function () {
            $consumer = new ImportedRecursiveTreeConsumer();

            expect($consumer->consume([
                'value' => 10,
                'children' => [
                    ['value' => 20, 'children' => []],
                ],
            ]))->toBeTrue();

            expect(fn () => $consumer->consume([
                'value' => 10,
                'children' => [
                    ['value' => 'not-int', 'children' => []],
                ],
            ]))->toThrow(TypeError::class, 'must be of type int');
        });
    });

    describe('4. Inline @var, Cycle Safety & Class-Scope Preservation', function () {
        test('enforces recursive alias on inline @var assignments inside class methods', function () {
            $service = new RecursiveTreeService();

            $valid = ['value' => 1, 'children' => [['value' => 2, 'children' => []]]];
            expect($service->assignInlineTree($valid))->toBe($valid);

            $invalid = ['value' => 1, 'children' => [['value' => 'bad', 'children' => []]]];
            expect(fn () => $service->assignInlineTree($invalid))
                ->toThrow(TypeError::class, 'must be of type int')
            ;
        });

        test('does not hang on direct circular aliases or cyclic PHP by-reference arrays', function () {
            $direct = new DirectCircularAliasFixture();
            expect($direct->accept('anything'))->toBe('anything');

            $service = new RecursiveTreeService();
            $cyclic = ['value' => 1, 'children' => []];
            $cyclic['children'][] = &$cyclic;

            expect($service->processTree($cyclic))->toBeTrue();
        });

        test('preserves class/declaration scoping by not leaking detached top-level aliases to functions', function () {
            expect(fn () => standaloneDetachedAliasFn(['value' => 1, 'children' => []]))
                ->toThrow(TypeError::class, 'must be of type DetachedTree')
            ;
        });
    });
});
