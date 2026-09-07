<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('customers.database.tables.customers', 'customers');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $this->assertRequiredColumns($tableName);
        $this->assertCompleteOwnerTuples($tableName);
        $this->assertNoDuplicateEmails($tableName);

        $ownedIndexName = $this->ownedIndexName($tableName);
        $globalIndexName = $this->globalIndexName($tableName);

        if (Schema::hasIndex($tableName, $ownedIndexName)
            && Schema::hasIndex($tableName, $globalIndexName)) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException(sprintf(
                'Customer email uniqueness migration cannot run on unsupported database driver [%s].',
                $driver,
            ));
        }

        $this->createOwnedIndex($tableName, $ownedIndexName, $driver);
        $this->createGlobalIndex($tableName, $globalIndexName, $driver);
    }

    private function assertRequiredColumns(string $tableName): void
    {
        foreach (['email', 'owner_type', 'owner_id'] as $columnName) {
            if (Schema::hasColumn($tableName, $columnName)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Customer email uniqueness migration cannot run because [%s] is missing column [%s].',
                $tableName,
                $columnName,
            ));
        }
    }

    private function assertCompleteOwnerTuples(string $tableName): void
    {
        $partialOwnerRows = DB::table($tableName)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $nested): void {
                    $nested->whereNull('owner_type')->whereNotNull('owner_id');
                })->orWhere(function (Builder $nested): void {
                    $nested->whereNotNull('owner_type')->whereNull('owner_id');
                });
            })
            ->count();

        if ($partialOwnerRows === 0) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Customer email uniqueness migration blocked: [%s] contains %d partially-owned rows. '
            . 'Owner type and owner id must both be null for global rows or both be present for owned rows.',
            $tableName,
            $partialOwnerRows,
        ));
    }

    private function assertNoDuplicateEmails(string $tableName): void
    {
        $duplicateGroups = DB::table($tableName)
            ->select('owner_type', 'owner_id')
            ->selectRaw('LOWER(TRIM(email)) AS normalized_email')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->whereNotNull('email')
            ->groupBy('owner_type', 'owner_id')
            ->groupByRaw('LOWER(TRIM(email))')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        if ($duplicateGroups->isEmpty()) {
            return;
        }

        $duplicateRows = 0;
        $ownedGroups = 0;
        $globalGroups = 0;

        foreach ($duplicateGroups as $duplicateGroup) {
            $duplicateRows += (int) $duplicateGroup->duplicate_count;

            if ($duplicateGroup->owner_type === null && $duplicateGroup->owner_id === null) {
                $globalGroups++;
            } else {
                $ownedGroups++;
            }
        }

        throw new RuntimeException(sprintf(
            'Customer email uniqueness migration blocked: [%s] contains %d duplicate normalized-email groups '
            . 'covering %d rows (%d owned groups, %d global groups). '
            . 'Resolve the duplicates without dropping data, then rerun the migration.',
            $tableName,
            $duplicateGroups->count(),
            $duplicateRows,
            $ownedGroups,
            $globalGroups,
        ));
    }

    private function createOwnedIndex(string $tableName, string $indexName, string $driver): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedIndex = $grammar->wrap($indexName);
        $ownerType = $grammar->wrap('owner_type');
        $ownerId = $grammar->wrap('owner_id');
        $email = sprintf('LOWER(TRIM(%s))', $grammar->wrap('email'));

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s, %s, %s) '
                . 'WHERE %s IS NOT NULL AND %s IS NOT NULL AND %s IS NOT NULL',
                $wrappedIndex,
                $wrappedTable,
                $ownerType,
                $ownerId,
                $email,
                $ownerType,
                $ownerId,
                $grammar->wrap('email'),
            ));

            return;
        }

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s, %s, (%s))',
            $wrappedIndex,
            $wrappedTable,
            $ownerType,
            $ownerId,
            $email,
        ));
    }

    private function createGlobalIndex(string $tableName, string $indexName, string $driver): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedIndex = $grammar->wrap($indexName);
        $ownerType = $grammar->wrap('owner_type');
        $ownerId = $grammar->wrap('owner_id');
        $email = sprintf('LOWER(TRIM(%s))', $grammar->wrap('email'));

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s) '
                . 'WHERE %s IS NULL AND %s IS NULL AND %s IS NOT NULL',
                $wrappedIndex,
                $wrappedTable,
                $email,
                $ownerType,
                $ownerId,
                $grammar->wrap('email'),
            ));

            return;
        }

        $globalEmail = sprintf(
            'CASE WHEN %s IS NULL AND %s IS NULL THEN %s ELSE NULL END',
            $ownerType,
            $ownerId,
            $email,
        );

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s ((%s))',
            $wrappedIndex,
            $wrappedTable,
            $globalEmail,
        ));
    }

    private function ownedIndexName(string $tableName): string
    {
        return $this->indexPrefix($tableName) . '_owner_email_unique';
    }

    private function globalIndexName(string $tableName): string
    {
        return $this->indexPrefix($tableName) . '_global_email_unique';
    }

    private function indexPrefix(string $tableName): string
    {
        return str_replace('.', '_', $tableName);
    }
};
