<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * @phpstan-import-type SharedRecordShape from NestedAliasTypes as ImportedRecordShape
 * @phpstan-import-type MidShape from NestedAliasChainedB as ChainedShape
 *
 * @phpstan-type LocalId positive-int
 * @phpstan-type LocalStatus 'active'|'pending'
 * @phpstan-type LocalRecordShape array{id: LocalId, status: LocalStatus}
 * @phpstan-type LocalRecordList list<LocalRecordShape>
 * @phpstan-type ImportedRecordList list<ImportedRecordShape>
 * @phpstan-type AdminStatus 'admin_active'
 * @phpstan-type UserStatus 'user_active'
 * @phpstan-type UnionOfAliases AdminStatus|UserStatus
 */
class NestedAliasService
{
    /**
     * @param LocalRecordList $records
     */
    public function saveLocalRecords(array $records): bool
    {
        return true;
    }

    /**
     * @param ImportedRecordList $records
     */
    public function saveImportedRecords(array $records): bool
    {
        return true;
    }

    /**
     * @param ChainedShape $data
     */
    public function saveChainedData(array $data): bool
    {
        return true;
    }

    /**
     * @param UnionOfAliases $status
     */
    public function setUnionStatus(string $status): bool
    {
        return true;
    }
}
