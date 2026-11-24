<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

use ArrayObject;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\TypePHP;

class UnsetPropBox
{
    /**
     * @var array{id: positive-int, name: non-empty-string, nickname?: non-empty-string}
     */
    public array $user = [
        'id' => 1,
        'name' => 'Alice',
        'nickname' => 'Ali',
    ];

    /**
     * @var list<int>
     */
    public array $numbers = [10, 20, 30];

    /**
     * @var non-empty-array<string, positive-int>
     */
    public array $nonEmpty = ['only' => 100];
}

class UnsetPropStaticBox
{
    /**
     * @var array{status: 'paid', amount: positive-int, note?: string}
     */
    public static array $receipt = [
        'status' => 'paid',
        'amount' => 100,
        'note' => 'ok',
    ];

    /**
     * @var list<int>
     */
    public static array $numbers = [10, 20, 30];

    public static function reset(): void
    {
        self::$receipt = [
            'status' => 'paid',
            'amount' => 100,
            'note' => 'ok',
        ];
        self::$numbers = [10, 20, 30];
    }
}

/**
 * @template T
 */
class UnsetGenericListBox
{
    /**
     * @var non-empty-list<T>
     */
    public array $items;

    /**
     * @param non-empty-list<T> $items
     */
    public function __construct(array $items)
    {
        $this->items = $items;
    }
}

class UnsetIgnoredCaller
{
    /**
     * @typephp-ignore
     */
    public static function unsetRequiredShapeKey(): array
    {
        /** @var array{status: 'paid', amount: positive-int} $receipt */
        $receipt = ['status' => 'paid', 'amount' => 100];

        unset($receipt['amount']);

        return $receipt;
    }

    /**
     * @typephp-ignore
     */
    public static function unsetPropertyKey(UnsetPropBox $box): void
    {
        unset($box->user['name']);
    }
}

describe('Unset Mutation Enforcement (unset($arr[$k]) & unset($obj->prop))', function () {
    beforeEach(function () {
        Config::reset();
        TypePHP::clearViolations();
        UnsetPropStaticBox::reset();
    });

    afterEach(function () {
        UnsetPropStaticBox::reset();
        TypePHP::clearViolations();
        Config::reset();
    });

    describe('1. Direct Bug Reproduction & Cascading State Corruption', function () {
        test('rejects unsetting a required array shape key and prevents cascading undefined key warning', function () {
            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            expect(function () use (&$receipt) {
                unset($receipt['amount']);
            })->toThrow(TypeError::class, "Variable \$receipt is missing required key 'amount'");

            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);

            $warnings = [];
            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                $warnings[] = $errstr;

                return true;
            });

            try {
                expect(function () use (&$receipt) {
                    $receipt['amount'] += -200;
                })->toThrow(TypeError::class, "Variable \$receipt['amount'] must be of type positive-int");
            } finally {
                restore_error_handler();
            }

            expect($warnings)->toBeEmpty();
        });

        test('rejects unsetting the only remaining required key on a 1-key shape (bypasses empty-array init exemption)', function () {
            /** @var array{id: positive-int} $single */
            $single = ['id' => 1];

            expect(function () use (&$single) {
                unset($single['id']);
            })->toThrow(TypeError::class, "Variable \$single is missing required key 'id'");

            expect($single)->toBe(['id' => 1]);
        });
    });

    describe('2. Array Shapes (Required, Optional, Nested, Unsealed, Positional Tuples)', function () {
        test('allows unsetting optional and non-existent keys on an array shape', function () {
            /** @var array{status: 'paid', amount: positive-int, refunded_at?: non-empty-string} $receipt */
            $receipt = [
                'status' => 'paid',
                'amount' => 100,
                'refunded_at' => '2026-01-01',
            ];

            unset($receipt['refunded_at']);
            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);

            unset($receipt['non_existent_key']);
            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);
        });

        test('enforces unsetting inside nested array shapes and preserves atomicity', function () {
            /** @var array{user: array{id: positive-int, name: non-empty-string, nickname?: non-empty-string}} $payload */
            $payload = [
                'user' => [
                    'id' => 1,
                    'name' => 'Alice',
                    'nickname' => 'Ali',
                ],
            ];

            unset($payload['user']['nickname']);
            expect($payload['user'])->toBe(['id' => 1, 'name' => 'Alice']);

            expect(function () use (&$payload) {
                unset($payload['user']['name']);
            })->toThrow(TypeError::class, "Variable \$payload['user'] is missing required key 'name'");

            expect($payload['user']['name'])->toBe('Alice');
        });

        test('enforces unsetting required key vs extra key on unsealed array shapes', function () {
            /** @var array{id: positive-int, ...<string, string>} $payload */
            $payload = ['id' => 42, 'extra' => 'meta'];

            unset($payload['extra']);
            expect($payload)->toBe(['id' => 42]);

            expect(function () use (&$payload) {
                unset($payload['id']);
            })->toThrow(TypeError::class, "Variable \$payload is missing required key 'id'");

            expect($payload)->toBe(['id' => 42]);
        });

        test('enforces required vs optional elements on positional tuple shapes', function () {
            /** @var array{0: positive-int, 1?: non-empty-string} $tuple */
            $tuple = [10, 'label'];

            unset($tuple[1]);
            expect($tuple)->toBe([10]);

            expect(function () use (&$tuple) {
                unset($tuple[0]);
            })->toThrow(TypeError::class, "Variable \$tuple is missing required key '0'");

            expect($tuple)->toBe([10]);
        });
    });

    describe('3. Lists & Non-Empty Containers (list<T>, non-empty-list<T>, non-empty-array<K, V>)', function () {
        test('rejects interior unset on list<int> that creates key gaps, while allowing trailing unset', function () {
            /** @var list<int> $items */
            $items = [10, 20, 30];

            expect(function () use (&$items) {
                unset($items[0]);
            })->toThrow(TypeError::class, 'Variable $items must be a list');

            expect($items)->toBe([10, 20, 30]);

            expect(function () use (&$items) {
                unset($items[1]);
            })->toThrow(TypeError::class, 'Variable $items must be a list');

            expect($items)->toBe([10, 20, 30]);

            unset($items[2]);
            expect($items)->toBe([10, 20]);

            unset($items[1], $items[0]);
            expect($items)->toBe([]);
        });

        test('rejects unsetting the last element of non-empty-list<int>', function () {
            /** @var non-empty-list<int> $items */
            $items = [10, 20];

            unset($items[1]);
            expect($items)->toBe([10]);

            expect(function () use (&$items) {
                unset($items[0]);
            })->toThrow(TypeError::class, 'Variable $items must be a non-empty list');

            expect($items)->toBe([10]);
        });

        test('rejects unsetting the last entry of non-empty-array<string, int>', function () {
            /** @var non-empty-array<string, int> $map */
            $map = ['a' => 1, 'b' => 2];

            unset($map['a']);
            expect($map)->toBe(['b' => 2]);

            expect(function () use (&$map) {
                unset($map['b']);
            })->toThrow(TypeError::class, 'Variable $map must be a non-empty array');

            expect($map)->toBe(['b' => 2]);
        });

        test('rejects interior unset on large list<int> even when array_validation is hybrid', function () {
            Config::set(['array_validation' => 'hybrid']);

            /** @var list<int> $largeList */
            $largeList = range(1, 200);

            expect(function () use (&$largeList) {
                unset($largeList[50]);
            })->toThrow(TypeError::class, 'Variable $largeList must be a list');

            expect(\count($largeList))->toBe(200)
                ->and(\array_key_exists(50, $largeList))->toBeTrue()
            ;
        });
    });

    describe('4. Object Shapes (object{...}, stdClass{...}) & ArrayAccess Controls', function () {
        test('rejects unsetting required property on object shape and allows optional property unset', function () {
            /** @var object{id: positive-int, name: non-empty-string, nickname?: non-empty-string} $user */
            $user = (object) ['id' => 1, 'name' => 'Alice', 'nickname' => 'Ali'];

            unset($user->nickname);
            expect(property_exists($user, 'nickname'))->toBeFalse();

            expect(function () use ($user) {
                unset($user->name);
            })->toThrow(TypeError::class, "Variable \$user is missing required property 'name'");

            expect($user->name)->toBe('Alice');
        });

        test('rejects unsetting required property on strict stdClass shape and nested structures', function () {
            /** @var \stdClass{profile: object{email: non-empty-string}, items: non-empty-list<positive-int>} $data */
            $data = (object) [
                'profile' => (object) ['email' => 'alice@example.com'],
                'items' => [10],
            ];

            expect(function () use ($data) {
                unset($data->profile->email);
            })->toThrow(TypeError::class, "Variable \$data->profile is missing required property 'email'");

            expect($data->profile->email)->toBe('alice@example.com');

            expect(function () use ($data) {
                unset($data->items[0]);
            })->toThrow(TypeError::class, 'Variable $data->items must be a non-empty list');

            expect($data->items)->toBe([10]);
        });

        test('allows unsetting keys on generic ArrayAccess object (ArrayObject<string, positive-int>)', function () {
            /** @var ArrayObject<string, positive-int> $map */
            $map = new ArrayObject(['a' => 10, 'b' => 20]);

            unset($map['a']);

            expect(\count($map))->toBe(1)
                ->and($map['b'])->toBe(20)
            ;
        });
    });

    describe('5. Multi-Argument unset(), Impure Targets & By-Reference Aliases', function () {
        test('validates multi-argument unset($a, $b) cumulatively and atomically when emptying non-empty-array', function () {
            /** @var non-empty-array<string, int> $map */
            $map = ['a' => 1, 'b' => 2];

            expect(function () use (&$map) {
                unset($map['a'], $map['b']);
            })->toThrow(TypeError::class, 'Variable $map must be a non-empty array');

            expect($map)->toBe(['a' => 1, 'b' => 2]);
        });

        test('evaluates side-effecting receiver and index expressions only once during unset', function () {
            $box = new UnsetPropBox();
            $boxCalls = 0;
            $getBox = static function () use ($box, &$boxCalls): UnsetPropBox {
                $boxCalls++;

                return $box;
            };

            $idx = 2;
            $getIdx = static function () use (&$idx): int {
                return $idx--;
            };

            unset($getBox()->numbers[$getIdx()]);
            expect($boxCalls)->toBe(1)
                ->and($idx)->toBe(1)
                ->and($box->numbers)->toBe([10, 20])
            ;

            expect(function () use ($getBox) {
                unset($getBox()->user['name']);
            })->toThrow(TypeError::class, "is missing required key 'name'");

            expect($boxCalls)->toBe(2)
                ->and($box->user['name'])->toBe('Alice')
            ;
        });

        test('enforces unset on by-reference variable alias ($alias = &$receipt)', function () {
            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            $alias = &$receipt;

            expect(function () use (&$alias) {
                unset($alias['amount']);
            })->toThrow(TypeError::class, "Variable \$alias is missing required key 'amount'");

            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100])
                ->and($alias)->toBe(['status' => 'paid', 'amount' => 100])
            ;
        });

        test('allows unsetting an entire local variable and unregistering its @var contract', function () {
            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            unset($receipt);

            expect(isset($receipt))->toBeFalse();

            $receipt = 'reassigned_after_unset';
            expect($receipt)->toBe('reassigned_after_unset');
        });
    });

    describe('6. Class Instance, Static & Generic Properties', function () {
        test('rejects invalid unset on instance property arrays/shapes and allows valid unsets', function () {
            $box = new UnsetPropBox();

            unset($box->user['nickname'], $box->numbers[2]);
            expect($box->user)->toBe(['id' => 1, 'name' => 'Alice'])
                ->and($box->numbers)->toBe([10, 20])
            ;

            expect(function () use ($box) {
                unset($box->user['name']);
            })->toThrow(TypeError::class, "is missing required key 'name'");

            expect($box->user['name'])->toBe('Alice');

            expect(function () use ($box) {
                unset($box->numbers[0]);
            })->toThrow(TypeError::class, 'must be a list');

            expect($box->numbers)->toBe([10, 20]);

            expect(function () use ($box) {
                unset($box->nonEmpty['only']);
            })->toThrow(TypeError::class, 'must be a non-empty array');

            expect($box->nonEmpty)->toBe(['only' => 100]);
        });

        test('rejects invalid unset on static property arrays/shapes', function () {
            unset(UnsetPropStaticBox::$receipt['note'], UnsetPropStaticBox::$numbers[2]);
            expect(UnsetPropStaticBox::$receipt)->toBe(['status' => 'paid', 'amount' => 100])
                ->and(UnsetPropStaticBox::$numbers)->toBe([10, 20])
            ;

            expect(function () {
                unset(UnsetPropStaticBox::$receipt['amount']);
            })->toThrow(TypeError::class, "is missing required key 'amount'");

            expect(UnsetPropStaticBox::$receipt['amount'])->toBe(100);

            expect(function () {
                unset(UnsetPropStaticBox::$numbers[0]);
            })->toThrow(TypeError::class, 'must be a list');

            expect(UnsetPropStaticBox::$numbers)->toBe([10, 20]);
        });

        test('rejects emptying non-empty-list generic property on pre-bound class instance', function () {
            /** @var UnsetGenericListBox<positive-int> $box */
            $box = new UnsetGenericListBox([10]);

            expect(function () use ($box) {
                unset($box->items[0]);
            })->toThrow(TypeError::class, 'must be a non-empty list');

            expect($box->items)->toBe([10]);
        });
    });

    describe('7. Closures, Config Toggles, Ignore Tags & Report Mode', function () {
        test('enforces unset inside closures capturing @var variables', function () {
            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            $byValue = function () use ($receipt): void {
                unset($receipt['amount']);
            };

            expect($byValue)->toThrow(TypeError::class, "is missing required key 'amount'");

            $byRef = function () use (&$receipt): void {
                unset($receipt['amount']);
            };

            expect($byRef)->toThrow(TypeError::class, "is missing required key 'amount'");
            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);
        });

        test('respects global enabled => false and inline_vars config toggles', function () {
            Config::set(['enabled' => false]);

            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];
            unset($receipt['amount']);
            expect($receipt)->toBe(['status' => 'paid']);

            Config::reset();
            Config::set(['inline_vars' => ['arrays' => false, 'properties' => false]]);

            /** @var array{status: 'paid', amount: positive-int} $receipt2 */
            $receipt2 = ['status' => 'paid', 'amount' => 100];
            unset($receipt2['amount']);
            expect($receipt2)->toBe(['status' => 'paid']);

            $box = new UnsetPropBox();
            unset($box->user['name']);
            expect($box->user)->toBe(['id' => 1, 'nickname' => 'Ali']);
        });

        test('respects @typephp-ignore on calling method', function () {
            $result = UnsetIgnoredCaller::unsetRequiredShapeKey();
            expect($result)->toBe(['status' => 'paid']);

            $box = new UnsetPropBox();
            UnsetIgnoredCaller::unsetPropertyKey($box);
            expect($box->user)->toBe(['id' => 1, 'nickname' => 'Ali']);
        });

        test('records violation without throwing when on_violation is report', function () {
            Config::set(['on_violation' => 'report']);

            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            unset($receipt['amount']);

            expect($receipt)->toBe(['status' => 'paid']);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('variable')
                ->and($violations[0]->target)->toBe('$receipt')
                ->and($violations[0]->expected)->toBe("required 'amount'")
            ;
        });
    });
});
