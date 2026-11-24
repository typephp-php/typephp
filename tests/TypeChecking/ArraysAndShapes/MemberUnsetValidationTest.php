<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Util\Config;
use TypePHP\TypePHP;

class MemberUnsetPropertyBox
{
    /**
     * @var array{status: 'paid', amount: positive-int, note?: string}
     */
    public array $receipt = [
        'status' => 'paid',
        'amount' => 100,
        'note' => 'initial',
    ];

    /**
     * @var list<positive-int>
     */
    public array $numbers = [10, 20, 30];

    /**
     * @var non-empty-list<positive-int>
     */
    public array $nonEmptyNumbers = [10];

    /**
     * @var array{items: list<positive-int>, meta?: array{tag: string}}
     */
    public array $nested = [
        'items' => [1, 2, 3],
        'meta' => ['tag' => 'v1'],
    ];
}

class MemberUnsetStaticBox
{
    /**
     * @var list<int>
     */
    public static array $numbers = [1, 2, 3];

    /**
     * @var array{status: 'paid', amount: positive-int}
     */
    public static array $receipt = [
        'status' => 'paid',
        'amount' => 100,
    ];

    public static function reset(): void
    {
        self::$numbers = [1, 2, 3];
        self::$receipt = ['status' => 'paid', 'amount' => 100];
    }
}

class MemberUnsetBoxFactory
{
    public int $calls = 0;

    public MemberUnsetPropertyBox $box;

    public function __construct()
    {
        $this->box = new MemberUnsetPropertyBox();
    }

    public function next(): MemberUnsetPropertyBox
    {
        $this->calls++;

        return $this->box;
    }
}

describe('Member Unset Validation (Red Phase)', function () {
    beforeEach(function () {
        MemberUnsetStaticBox::reset();
        Config::reset();
        TypePHP::clearViolations();
    });

    afterEach(function () {
        MemberUnsetStaticBox::reset();
        TypePHP::clearViolations();
        Config::reset();
    });

    describe('1. Array Shapes (Required, Optional, Unsealed, Nested, Tuples & Aliases)', function () {
        test('reproduces playground bug: rejects unset on required key and prevents Undefined array key in subsequent compound op', function () {
            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            $warnings = [];
            set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
                $warnings[] = $errstr;

                return true;
            });

            try {
                expect(function () use (&$receipt) {
                    unset($receipt['amount']);
                })->toThrow(TypeError::class, "Variable \$receipt is missing required key 'amount'");

                expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);

                expect(function () use (&$receipt) {
                    $receipt['amount'] += -200;
                })->toThrow(TypeError::class, "Variable \$receipt['amount'] must be of type positive-int");
            } finally {
                restore_error_handler();
            }

            expect($warnings)->toBeEmpty();
        });

        test('rejects unsetting the sole required key of a 1-key array shape (must not hit empty-array init bypass)', function () {
            /** @var array{amount: positive-int} $single */
            $single = ['amount' => 100];

            expect(function () use (&$single) {
                unset($single['amount']);
            })->toThrow(TypeError::class, "Variable \$single is missing required key 'amount'");

            expect($single)->toBe(['amount' => 100]);
        });

        test('allows unsetting optional keys (including already-absent optional keys) on an array shape', function () {
            /** @var array{status: 'paid', amount: positive-int, note?: string} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100, 'note' => 'promo'];

            unset($receipt['note']);
            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);

            unset($receipt['note']);
            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);
        });

        test('allows unsetting extra keys on an unsealed shape while rejecting required keys', function () {
            /** @var array{status: 'paid', ...<string, string>} $receipt */
            $receipt = ['status' => 'paid', 'ref' => 'abc-123'];

            unset($receipt['ref']);
            expect($receipt)->toBe(['status' => 'paid']);

            expect(function () use (&$receipt) {
                unset($receipt['status']);
            })->toThrow(TypeError::class, "Variable \$receipt is missing required key 'status'");

            expect($receipt)->toBe(['status' => 'paid']);
        });

        test('validates nested array shape unset and preserves state atomically', function () {
            /** @var array{user: array{id: positive-int, name?: non-empty-string}} $config */
            $config = [
                'user' => [
                    'id' => 42,
                    'name' => 'Alice',
                ],
            ];

            unset($config['user']['name']);
            expect($config)->toBe(['user' => ['id' => 42]]);

            expect(function () use (&$config) {
                unset($config['user']['id']);
            })->toThrow(TypeError::class, "Variable \$config['user'] is missing required key 'id'");

            expect($config)->toBe(['user' => ['id' => 42]]);
        });

        test('follows by-reference alias when unsetting a required key', function () {
            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            $alias = &$receipt;

            expect(function () use (&$alias) {
                unset($alias['amount']);
            })->toThrow(TypeError::class, "Variable \$alias is missing required key 'amount'");

            expect($receipt)->toBe(['status' => 'paid', 'amount' => 100]);
        });

        test('enforces required vs optional elements on positional tuple shapes', function () {
            /** @var array{0: positive-int, 1?: non-empty-string} $tuple */
            $tuple = [10, 'hello'];

            unset($tuple[1]);
            expect($tuple)->toBe([10]);

            expect(function () use (&$tuple) {
                unset($tuple[0]);
            })->toThrow(TypeError::class, "Variable \$tuple is missing required key '0'");

            expect($tuple)->toBe([10]);
        });
    });

    describe('2. Lists & Non-Empty Containers (list<T>, non-empty-list<T>, non-empty-array<K, V>)', function () {
        test('rejects unsetting non-trailing indices in list<T> because it breaks sequential keys', function () {
            /** @var list<int> $list */
            $list = [1, 2, 3];

            expect(function () use (&$list) {
                unset($list[0]);
            })->toThrow(TypeError::class, 'Variable $list must be a list');

            expect($list)->toBe([1, 2, 3]);

            expect(function () use (&$list) {
                unset($list[1]);
            })->toThrow(TypeError::class, 'Variable $list must be a list');

            expect($list)->toBe([1, 2, 3]);
        });

        test('allows unsetting the trailing index of list<T>', function () {
            /** @var list<int> $list */
            $list = [1, 2, 3];

            unset($list[2]);
            expect($list)->toBe([1, 2]);
        });

        test('rejects unsetting the last remaining element of non-empty-list<T>', function () {
            /** @var non-empty-list<int> $list */
            $list = [10, 20];

            unset($list[1]);
            expect($list)->toBe([10]);

            expect(function () use (&$list) {
                unset($list[0]);
            })->toThrow(TypeError::class, 'Variable $list must be a non-empty list');

            expect($list)->toBe([10]);
        });

        test('rejects unsetting the last remaining element of non-empty-array<K, V> while allowing partial unset', function () {
            /** @var non-empty-array<string, int> $map */
            $map = ['a' => 1, 'b' => 2];

            unset($map['a']);
            expect($map)->toBe(['b' => 2]);

            expect(function () use (&$map) {
                unset($map['b']);
            })->toThrow(TypeError::class, 'Variable $map must be a non-empty array');

            expect($map)->toBe(['b' => 2]);
        });

        test('evaluates multi-target unset on the same root variable atomically as a single state transition', function () {
            /** @var list<int> $list */
            $list = [10, 20];

            // Final state [] IS a valid list<int> even though unsetting index 0 alone would create a gap
            unset($list[0], $list[1]);
            expect($list)->toBe([]);

            /** @var non-empty-list<int> $nonEmpty */
            $nonEmpty = [10, 20];

            // Final state [] violates non-empty-list<int>; neither element should be unset
            expect(function () use (&$nonEmpty) {
                unset($nonEmpty[0], $nonEmpty[1]);
            })->toThrow(TypeError::class, 'Variable $nonEmpty must be a non-empty list');

            expect($nonEmpty)->toBe([10, 20]);
        });
    });

    describe('3. Object Shapes (object{...})', function () {
        test('rejects unsetting required property and allows unsetting optional property on stdClass shape', function () {
            /** @var object{id: positive-int, name: non-empty-string, note?: string} $obj */
            $obj = (object) ['id' => 1, 'name' => 'Alice', 'note' => 'vip'];

            unset($obj->note);
            expect(property_exists($obj, 'note'))->toBeFalse();

            expect(function () use (&$obj) {
                unset($obj->name);
            })->toThrow(TypeError::class, "Variable \$obj is missing required property 'name'");

            expect($obj->name)->toBe('Alice');
        });

        test('rejects unsetting nested required object shape property', function () {
            /** @var object{user: object{id: positive-int}} $data */
            $data = (object) ['user' => (object) ['id' => 10]];

            expect(function () use (&$data) {
                unset($data->user->id);
            })->toThrow(TypeError::class, "Variable \$data->user is missing required property 'id'");

            expect($data->user->id)->toBe(10);
        });
    });

    describe('4. Class & Static Property Member Unset', function () {
        test('rejects unsetting required shape key and allows optional key on object property', function () {
            $box = new MemberUnsetPropertyBox();

            unset($box->receipt['note']);
            expect($box->receipt)->toBe(['status' => 'paid', 'amount' => 100]);

            expect(function () use ($box) {
                unset($box->receipt['amount']);
            })->toThrow(TypeError::class, "is missing required key 'amount'");

            expect($box->receipt)->toBe(['status' => 'paid', 'amount' => 100]);
        });

        test('rejects unsetting non-trailing list element and emptying non-empty-list on object property', function () {
            $box = new MemberUnsetPropertyBox();

            unset($box->numbers[2]);
            expect($box->numbers)->toBe([10, 20]);

            expect(function () use ($box) {
                unset($box->numbers[0]);
            })->toThrow(TypeError::class, 'must be a list');

            expect($box->numbers)->toBe([10, 20]);

            expect(function () use ($box) {
                unset($box->nonEmptyNumbers[0]);
            })->toThrow(TypeError::class, 'must be a non-empty list');

            expect($box->nonEmptyNumbers)->toBe([10]);
        });

        test('validates nested property member unset ($box->nested["items"][0])', function () {
            $box = new MemberUnsetPropertyBox();

            unset($box->nested['meta']);
            expect($box->nested)->toBe(['items' => [1, 2, 3]]);

            expect(function () use ($box) {
                unset($box->nested['items'][0]);
            })->toThrow(TypeError::class, "['items'] must be a list");

            expect($box->nested['items'])->toBe([1, 2, 3]);
        });

        test('validates static property member unset (StaticBox::$numbers[0] & StaticBox::$receipt["amount"])', function () {
            expect(function () {
                unset(MemberUnsetStaticBox::$numbers[0]);
            })->toThrow(TypeError::class, 'must be a list');

            expect(MemberUnsetStaticBox::$numbers)->toBe([1, 2, 3]);

            expect(function () {
                unset(MemberUnsetStaticBox::$receipt['amount']);
            })->toThrow(TypeError::class, "is missing required key 'amount'");

            expect(MemberUnsetStaticBox::$receipt)->toBe(['status' => 'paid', 'amount' => 100]);
        });
    });

    describe('5. Side-Effecting Target Single-Evaluation', function () {
        test('evaluates impure property receiver and array index only once on unset', function () {
            $factory = new MemberUnsetBoxFactory();

            // Valid trailing unset on impure receiver
            unset($factory->next()->numbers[2]);
            expect($factory->calls)->toBe(1)
                ->and($factory->box->numbers)->toBe([10, 20])
            ;

            // Failing unset on impure receiver still evaluates next() only once
            expect(function () use ($factory) {
                unset($factory->next()->numbers[0]);
            })->toThrow(TypeError::class, 'must be a list');

            expect($factory->calls)->toBe(2)
                ->and($factory->box->numbers)->toBe([10, 20])
            ;

            // Impure array index expression on local list variable
            /** @var list<int> $list */
            $list = [1, 2, 3];
            $idx = 2;
            unset($list[$idx++]);

            expect($idx)->toBe(3)
                ->and($list)->toBe([1, 2])
            ;
        });
    });

    describe('6. Bare Variable Unset, Static Elimination, Zero Line-Drift & Violation Modes', function () {
        test('allows bare unset($receipt) without throwing and unregisters its scope binding', function () {
            /** @var array{status: 'paid', amount: positive-int} $receipt */
            $receipt = ['status' => 'paid', 'amount' => 100];

            unset($receipt);

            expect(isset($receipt))->toBeFalse();

            // After bare unset, reassigning without a new @var must not enforce the old contract
            $receipt = 'completely_different';
            expect($receipt)->toBe('completely_different');
        });

        test('allows and statically eliminates unset checks on plain associative maps (array<K, V>, T[])', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

/** @var array<string, positive-int> $map */
$map = ['a' => 1, 'b' => 2];
unset($map['a']);

/** @var int[] $nums */
$nums = [1, 2, 3];
unset($nums[0]);
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_unset_map_elimination.php');

            expect($transformed)->not()->toContain('checkMemberUnset');

            /** @var array<string, positive-int> $map */
            $map = ['a' => 1, 'b' => 2];
            unset($map['a']);
            expect($map)->toBe(['b' => 2]);
        });

        test('preserves exact line numbers (zero line-drift) when injecting unset guards', function () {
            $source = <<<'PHP'
<?php

declare(strict_types=1);

/** @var array{status: 'paid', amount: positive-int} $receipt */
$receipt = ['status' => 'paid', 'amount' => 100];

unset($receipt['amount']);

$landmark = 'exact_line_10';
PHP;

            $transformed = StreamWrapper::transformSource($source, 'test_unset_line_drift.php');

            $origLines = explode("\n", str_replace("\r\n", "\n", $source));
            $transLines = explode("\n", str_replace("\r\n", "\n", $transformed));

            expect(\count($transLines))->toBe(\count($origLines));

            $origIdx = array_search("\$landmark = 'exact_line_10';", array_map('trim', $origLines), true);
            $transIdx = array_search("\$landmark = 'exact_line_10';", array_map('trim', $transLines), true);

            expect($transIdx)->toBe($origIdx)
                ->and($origIdx)->toBe(9)
            ;
        });

        test('records violation without throwing in report mode and bypasses when enabled is false', function () {
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

            Config::set(['enabled' => false, 'on_violation' => 'throw']);

            /** @var array{status: 'paid', amount: positive-int} $disabledReceipt */
            $disabledReceipt = ['status' => 'paid', 'amount' => 100];
            unset($disabledReceipt['amount']);

            expect($disabledReceipt)->toBe(['status' => 'paid']);
        });
    });
});
