<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\InheritanceAndAttributes;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\CrossNamespace\App\CrossNamespaceAccount;
use TypePHP\Tests\Fixtures\CrossNamespace\Model\CrossNamespaceUser;
use TypePHP\Tests\Fixtures\Domain\Car;

describe('Cross-Namespace Property Resolution with Traits and Inheritance', function () {
    test('resolves relative @var types in parent class namespace when assigned from trait in child namespace (User Bug Report)', function () {
        $account = new CrossNamespaceAccount();

        $account->setOwner();

        expect($account->owner)->toBeInstanceOf(CrossNamespaceUser::class);
    });

    test('still enforces type validation when assigning invalid object across namespaces', function () {
        $account = new CrossNamespaceAccount();

        expect(function () use ($account) {
            $account->owner = new Car();
        })->toThrow(TypeError::class, CrossNamespaceUser::class);
    });
});
