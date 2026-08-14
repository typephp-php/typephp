<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Mid Bridge Class importing RootCode and embedding it in MidShape
 *
 * @phpstan-import-type RootCode from NestedAliasChainedA as MidCode
 *
 * @phpstan-type MidShape array{code: MidCode, label: non-empty-string}
 */
class NestedAliasChainedB
{
}
