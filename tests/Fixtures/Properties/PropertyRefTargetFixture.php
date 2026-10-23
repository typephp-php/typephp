<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Properties;

class PropertyRefTargetFixture
{
    /**
     * @var positive-int
     */
    public int $score = 10;

    /**
     * @var list<int>
     */
    public array $numbers = [1, 2, 3];

    /**
     * @var array{id: positive-int, name: non-empty-string}
     */
    public array $user = [
        'id' => 1,
        'name' => 'Alice',
    ];

    /**
     * @var positive-int
     */
    public static int $staticScore = 100;

    public static function reset(): void
    {
        self::$staticScore = 100;
    }

    public function mutateInternalScoreByRef(mixed $val): void
    {
        $ref = &$this->score;
        $ref = $val;
    }

    public function mutateInternalNumberByRef(mixed $val): void
    {
        $ref = &$this->numbers[0];
        $ref = $val;
    }

    public function mutateInternalUserByRef(mixed $val): void
    {
        $ref = &$this->user['name'];
        $ref = $val;
    }
}