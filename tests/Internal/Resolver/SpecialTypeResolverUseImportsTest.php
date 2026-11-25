<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Resolver;

use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Resolver\SpecialTypeResolver;

class UseImportCollisionTarget
{
}

describe('SpecialTypeResolver & StreamWrapper Non-Class Use Import Isolation (Red Phase)', function () {
    beforeEach(function () {
        SpecialTypeResolver::reset();
        StreamWrapper::reset();
    });

    afterEach(function () {
        SpecialTypeResolver::reset();
        StreamWrapper::reset();
    });

    test('parseFileMetadata ignores single, multi, group, and mixed use function / use const imports and does not leak group prefix', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Order as CustomerOrder;
use App\Contracts\{PaymentInterface, function helperFn, const MAX_RETRIES, RefundInterface as Refund};
use function App\Utils\calculateTotal as compute;
use function App\Utils\formatA, App\Utils\formatB as fmtB;
use function App\GroupFn\{fnOne, fnTwo as fn2};
use const App\Config\MAX_ITEMS as LIMIT;
use const App\Config\FLAG_A, App\Config\FLAG_B as FB;
use const App\GroupConst\{CONST_ONE, CONST_TWO as C2};

class DemoService {}
PHP;

        $virtualFile = 'VirtualNonClassUseImports.php';
        SpecialTypeResolver::parseFileMetadata($virtualFile, $source);

        $imports = SpecialTypeResolver::getUseImportsFromFile($virtualFile);

        expect($imports)->toBe([
            'User' => 'App\Models\User',
            'CustomerOrder' => 'App\Models\Order',
            'PaymentInterface' => 'App\Contracts\PaymentInterface',
            'Refund' => 'App\Contracts\RefundInterface',
        ])
            ->and($imports)->not()->toHaveKey('Contracts')
            ->and($imports)->not()->toHaveKey('helperFn')
            ->and($imports)->not()->toHaveKey('MAX_RETRIES')
            ->and($imports)->not()->toHaveKey('compute')
            ->and($imports)->not()->toHaveKey('formatA')
            ->and($imports)->not()->toHaveKey('fmtB')
            ->and($imports)->not()->toHaveKey('fnOne')
            ->and($imports)->not()->toHaveKey('fn2')
            ->and($imports)->not()->toHaveKey('LIMIT')
            ->and($imports)->not()->toHaveKey('FLAG_A')
            ->and($imports)->not()->toHaveKey('FB')
            ->and($imports)->not()->toHaveKey('CONST_ONE')
            ->and($imports)->not()->toHaveKey('C2')
        ;
    });

    test('resolveFqcnForFile resolves same-namespace class instead of colliding use function / use const import', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Resolver;

use function Some\Other\UseImportCollisionTarget;
use const Some\Other\OTHER_CONST as OtherCollision;

class Consumer {}
PHP;

        $virtualFile = 'VirtualUseImportCollision.php';
        SpecialTypeResolver::parseFileMetadata($virtualFile, $source);

        expect(SpecialTypeResolver::resolveFqcnForFile('UseImportCollisionTarget', $virtualFile))
            ->toBe(UseImportCollisionTarget::class)
        ;
    });

    test('StreamWrapper AST metadata seeding ignores group use function and use const imports', function () {
        $source = <<<'PHP'
<?php

declare(strict_types=1);

namespace App\StreamGroupUse;

use App\Models\{User, function loadUser, const DEFAULT_ROLE};
use function App\Helpers\{alpha, beta as b};
use const App\Flags\{FLAG_ONE, FLAG_TWO as F2};

class Handler {}
PHP;

        $virtualFile = 'VirtualStreamGroupUse.php';
        StreamWrapper::transformSource($source, $virtualFile);

        $imports = SpecialTypeResolver::getUseImportsFromFile($virtualFile);

        expect($imports)->toBe([
            'User' => 'App\Models\User',
        ]);
    });
});
