<?php

declare(strict_types=1);

use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\Config;

describe('RuntimeTypeChecker Simple Scope Plan Hot-Path Compilation', function () {
    beforeEach(function () {
        Config::reset();
        RuntimeTypeChecker::reset();
    });

    afterEach(function () {
        Config::reset();
        RuntimeTypeChecker::reset();
    });

    test('compiles class, interface, object, and nullable parameters to inline fast-path ops', function () {
        $target = new class () {
            /**
             * @param DateTimeInterface $date
             * @param stdClass|null $meta
             * @param object $anyObj
             * @param ?positive-int $limit
             * @param string|null $tag
             */
            public function handle(
                mixed $date,
                mixed $meta,
                mixed $anyObj,
                mixed $limit,
                mixed $tag
            ): void {
            }
        };

        $fn = $target::class . '::handle';
        $validVars = [
            'date' => new DateTimeImmutable(),
            'meta' => null,
            'anyObj' => new stdClass(),
            'limit' => 10,
            'tag' => null,
        ];

        expect(RuntimeTypeChecker::setupScope($fn, $validVars, $target))->toBeNull();

        $ref = new ReflectionClass(RuntimeTypeChecker::class);
        $plans = $ref->getProperty('simpleScopePlanCache')->getValue();
        expect($plans)->toHaveKey($fn);

        $itemsByName = [];
        foreach ($plans[$fn]['items'] as $item) {
            $itemsByName[$item[0]] = $item;
        }

        $opClass = $ref->getConstant('PARAM_CHECK_CLASS');
        $opObject = $ref->getConstant('PARAM_CHECK_OBJECT');
        $opPositiveInt = $ref->getConstant('PARAM_CHECK_POSITIVE_INT');
        $opString = $ref->getConstant('PARAM_CHECK_STRING');

        expect($itemsByName['date'][1])->toBe($opClass)
            ->and($itemsByName['date'][4])->toBe(DateTimeInterface::class)
            ->and($itemsByName['date'][5])->toBeFalse()
            ->and($itemsByName['meta'][1])->toBe($opClass)
            ->and($itemsByName['meta'][4])->toBe(stdClass::class)
            ->and($itemsByName['meta'][5])->toBeTrue()
            ->and($itemsByName['anyObj'][1])->toBe($opObject)
            ->and($itemsByName['anyObj'][5])->toBeFalse()
            ->and($itemsByName['limit'][1])->toBe($opPositiveInt)
            ->and($itemsByName['limit'][5])->toBeTrue()
            ->and($itemsByName['tag'][1])->toBe($opString)
            ->and($itemsByName['tag'][5])->toBeTrue()
        ;

        expect(RuntimeTypeChecker::setupScope($fn, [
            'date' => new DateTime(),
            'meta' => new stdClass(),
            'anyObj' => new DateTimeImmutable(),
            'limit' => null,
            'tag' => 'ok',
        ], $target))->toBeNull();

        $badDate = RuntimeTypeChecker::setupScope($fn, [...$validVars, 'date' => new stdClass()], $target);
        expect($badDate)->toBeInstanceOf(ErrorMessage::class)
            ->and($badDate->getMessage())->toContain('DateTimeInterface')
        ;

        $nullDate = RuntimeTypeChecker::setupScope($fn, [...$validVars, 'date' => null], $target);
        expect($nullDate)->toBeInstanceOf(ErrorMessage::class)
            ->and($nullDate->getMessage())->toContain('DateTimeInterface')
        ;

        $badMeta = RuntimeTypeChecker::setupScope($fn, [...$validVars, 'meta' => new DateTimeImmutable()], $target);
        expect($badMeta)->toBeInstanceOf(ErrorMessage::class)
            ->and($badMeta->getMessage())->toContain('stdClass')
        ;

        $badObj = RuntimeTypeChecker::setupScope($fn, [...$validVars, 'anyObj' => 'not-an-object'], $target);
        expect($badObj)->toBeInstanceOf(ErrorMessage::class)
            ->and($badObj->getMessage())->toContain('object')
        ;

        $badLimit = RuntimeTypeChecker::setupScope($fn, [...$validVars, 'limit' => 0], $target);
        expect($badLimit)->toBeInstanceOf(ErrorMessage::class)
            ->and($badLimit->getMessage())->toContain('positive-int')
        ;
    });
});
