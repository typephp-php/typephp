<?php

declare(strict_types=1);

class CallableSyntaxClass
{
    public static function sampleStaticMethod(): string
    {
        return 'ok';
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function getRelativeCallableArray(): array
    {
        return ['static', 'sampleStaticMethod'];
    }

    /**
     * @return string
     */
    public function getRelativeCallableString(): string
    {
        return 'static::sampleStaticMethod';
    }
}

describe('PHP 8.2+ Deprecated Callable Syntax ("static::method" and ["static", "method"])', function () {
    test('does not trigger PHP 8.2 deprecation warnings when returning relative callable arrays or strings', function () {
        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                $warnings[] = $errstr;

                return true;
            }

            return false;
        });

        try {
            $service = new CallableSyntaxClass();

            $arrayResult = $service->getRelativeCallableArray();
            expect($arrayResult)->toBe(['static', 'sampleStaticMethod']);

            $stringResult = $service->getRelativeCallableString();
            expect($stringResult)->toBe('static::sampleStaticMethod');
        } finally {
            restore_error_handler();
        }

        expect($warnings)->toBeEmpty();
    });
});
