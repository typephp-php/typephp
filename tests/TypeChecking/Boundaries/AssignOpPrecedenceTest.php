<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\InlineVars;

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Io\StreamWrapper;

final class AssignOpButtonFixture
{
    public ?string $tooltip = null;

    public string $direction = 'down';

    public function init(): void
    {
        $this->tooltip ??= $this->direction === 'up' ? 'VALIDATE' : 'CANCEL';
    }
}

final class AssignOpStrictPropertyFixture
{
    /**
     * @var ?non-empty-string
     */
    public ?string $tooltip = null;

    public string $direction = 'down';

    /**
     * @var positive-int
     */
    public int $multiplier = 4;

    /**
     * @var non-empty-string
     */
    public string $label = 'prefix';

    /**
     * @var array<int, ?string>
     */
    public array $slots = [0 => 'existing', 1 => null];

    public int $indexCalls = 0;

    public function initStrict(bool $useEmptyFallback = false): void
    {
        $this->tooltip ??= $useEmptyFallback ? '' : ($this->direction === 'up' ? 'VALIDATE' : 'CANCEL');
    }

    public function scale(int $a, int $b): void
    {
        $this->multiplier *= $a + $b;
    }

    public function appendLabel(bool $upper): void
    {
        $this->label .= $upper ? '_UP' : '_DOWN';
    }

    public function nextIndex(): int
    {
        return $this->indexCalls++;
    }

    public function fillNextSlot(): void
    {
        $this->slots[$this->nextIndex()] ??= $this->direction === 'up' ? 'VALIDATE' : 'CANCEL';
    }
}

describe('AssignOp RHS Operator Precedence Preservation', function () {
    test('preserves non-null property value when using ??= with a ternary RHS', function () {
        $button = new AssignOpButtonFixture();
        $button->tooltip = 'Week tooltip';
        $button->direction = 'down';
        $button->init();

        expect($button->tooltip)->toBe('Week tooltip');

        $button->direction = 'up';
        $button->init();

        expect($button->tooltip)->toBe('Week tooltip');
    });

    test('evaluates ternary fallback when property is null on ??=', function () {
        $downButton = new AssignOpButtonFixture();
        $downButton->direction = 'down';
        $downButton->init();

        $upButton = new AssignOpButtonFixture();
        $upButton->direction = 'up';
        $upButton->init();

        expect($downButton->tooltip)->toBe('CANCEL')
            ->and($upButton->tooltip)->toBe('VALIDATE')
        ;
    });

    test('enforces @var property contract on ??= ternary fallback while preserving existing non-null value', function () {
        $preserved = new AssignOpStrictPropertyFixture();
        $preserved->tooltip = 'Existing tooltip';
        $preserved->initStrict(useEmptyFallback: true);

        expect($preserved->tooltip)->toBe('Existing tooltip');

        $invalid = new AssignOpStrictPropertyFixture();
        expect(fn () => $invalid->initStrict(useEmptyFallback: true))
            ->toThrow(TypeError::class, 'must be of type non-empty-string')
        ;
    });

    test('preserves operator precedence for *= and .= with compound RHS on @var properties', function () {
        $fixture = new AssignOpStrictPropertyFixture();
        $fixture->scale(2, 3);
        $fixture->appendLabel(false);

        expect($fixture->multiplier)->toBe(20)
            ->and($fixture->label)->toBe('prefix_DOWN')
        ;
    });

    test('preserves operator precedence for ??= and .= with ternary RHS on local @var variables', function () {
        /** @var ?non-empty-string $tooltip */
        $tooltip = 'Keep me';
        $direction = 'down';
        $tooltip ??= $direction === 'up' ? 'VALIDATE' : 'CANCEL';

        expect($tooltip)->toBe('Keep me');

        /** @var ?non-empty-string $fallback */
        $fallback = null;
        $fallback ??= $direction === 'up' ? 'VALIDATE' : 'CANCEL';

        expect($fallback)->toBe('CANCEL');

        /** @var non-empty-string $text */
        $text = 'start';
        $flag = false;
        $text .= $flag ? '_A' : '_B';

        expect($text)->toBe('start_B');
    });

    test('preserves ??= ternary precedence when target array dim expression has side effects', function () {
        $fixture = new AssignOpStrictPropertyFixture();
        $fixture->direction = 'down';

        $fixture->fillNextSlot();
        $fixture->fillNextSlot();

        expect($fixture->indexCalls)->toBe(2)
            ->and($fixture->slots)->toBe([0 => 'existing', 1 => 'CANCEL'])
        ;
    });

    test('StreamWrapper parenthesizes ternary RHS when rewriting ??= on properties', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

final class PrecedenceButton
{
    public ?string $tooltip = null;
    public string $direction = 'down';

    public function init(): void
    {
        $this->tooltip ??= $this->direction === 'up' ? 'VALIDATE' : 'CANCEL';
    }
}
PHP;

        $transformed = StreamWrapper::transformSource($source, 'precedence_button_test.php');

        expect($transformed)->toContain("\$this->tooltip ?? (\$this->direction === 'up' ? 'VALIDATE' : 'CANCEL')");
    });
});
