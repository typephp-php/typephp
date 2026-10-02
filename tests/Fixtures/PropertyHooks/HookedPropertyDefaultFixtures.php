<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\PropertyHooks;

class BackedPropertyWithViolatingGetHook
{
    /**
     * @var positive-int
     */
    public int $count = 5 {
        get => -1;
        set(int $value) {
            $this->count = $value;
        }
    }
}

class HookedPropertyAccessingConstructorState
{
    private string $prefix;

    /**
     * @var non-empty-string
     */
    public string $title = 'default' {
        get => $this->prefix . ': ' . $this->title;
    }

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }
}

class MixedHookedAndRegularPropertiesClass
{
    /**
     * @var positive-int
     */
    public int $hookedCount = 10 {
        get => $this->hookedCount * 2;
    }

    /**
     * @var positive-int
     */
    public int $invalidDefault = -5;
}

class BasicTransformingHooks
{
    /**
     * @var non-empty-string
     */
    public string $greeting = 'hello' {
        get => strtoupper($this->greeting);
        set(string $value) {
            $this->greeting = trim($value);
        }
    }
}

class SetOnlyHookedModel
{
    /**
     * @var non-empty-string
     */
    public string $label = '' {
        set(string $value) {
            $this->label = trim($value);
        }
    }

    public function __construct(string $label)
    {
        $this->label = $label;
    }
}

class SetOnlyHookTransformingModel
{
    /**
     * @var positive-int
     */
    public int $score = 0 {
        set(int $value) {
            $this->score = $value;
        }
    }

    public function __construct(int $score)
    {
        $this->score = $score;
    }
}

class MixedSetOnlyAndUnprotectedPropertyModel
{
    /**
     * @var non-empty-string
     */
    public string $hooked = '' {
        set(string $value) {
            $this->hooked = strtoupper($value);
        }
    }

    /**
     * @var positive-int
     */
    public int $invalidDefault = -99;

    public function __construct(string $hooked)
    {
        $this->hooked = $hooked;
    }
}
