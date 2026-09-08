<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\ConnectionDriver;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string EMAIL_TYPE = 'email';

    public function up(): void
    {
        $tableName = (string) config('contacting.database.tables.contact_methods', 'contact_methods');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        $driver = ConnectionDriver::name(Schema::getConnection());

        if (! in_array($driver, ['mysql', 'pgsql', 'sqlite'], true)) {
            throw new RuntimeException(sprintf(
                'Customer email uniqueness migration cannot run on unsupported database driver [%s].',
                $driver,
            ));
        }

        $this->assertRequiredColumns($tableName);
        $this->assertCompleteOwnerTuples($tableName);
        $this->assertNoDuplicateEmails($tableName);

        $ownedIndexName = $this->indexPrefix($tableName) . '_customer_email_owner_unique';
        $globalIndexName = $this->indexPrefix($tableName) . '_customer_email_global_unique';

        $this->createOwnedIndex($tableName, $ownedIndexName, $driver);
        $this->createGlobalIndex($tableName, $globalIndexName, $driver);
    }

    private function assertRequiredColumns(string $tableName): void
    {
        foreach (['owner_type', 'owner_id', 'contactable_type', 'type', 'value', 'normalized_value'] as $columnName) {
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
            ->where('contactable_type', $this->customerMorphClass())
            ->where('type', self::EMAIL_TYPE)
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
            'Customer email uniqueness migration blocked: [%s] contains %d partially-owned email rows. '
            . 'Owner type and owner id must both be null for global rows or both be present for owned rows.',
            $tableName,
            $partialOwnerRows,
        ));
    }

    private function assertNoDuplicateEmails(string $tableName): void
    {
        $expression = $this->normalizedEmailExpression();

        $duplicateGroups = DB::table($tableName)
            ->select('owner_type', 'owner_id')
            ->selectRaw($expression . ' AS normalized_email')
            ->selectRaw('COUNT(*) AS duplicate_count')
            ->where('contactable_type', $this->customerMorphClass())
            ->where('type', self::EMAIL_TYPE)
            ->whereRaw($expression . ' IS NOT NULL')
            ->groupBy('owner_type', 'owner_id')
            ->groupByRaw($expression)
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
        $email = $this->normalizedEmailExpression();
        $customerType = $this->quotedCustomerMorphClass();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s, %s, %s) '
                . 'WHERE %s IS NOT NULL AND %s IS NOT NULL AND %s = %s AND %s = %s AND %s IS NOT NULL',
                $wrappedIndex,
                $wrappedTable,
                $ownerType,
                $ownerId,
                $email,
                $ownerType,
                $ownerId,
                $grammar->wrap('contactable_type'),
                $customerType,
                $grammar->wrap('type'),
                DB::connection()->getPdo()->quote(self::EMAIL_TYPE),
                $email,
            ));

            return;
        }

        $ownedEmail = $this->mysqlScopedEmailExpression($email, $customerType, owned: true);

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s (%s, %s, (%s))',
            $wrappedIndex,
            $wrappedTable,
            $ownerType,
            $ownerId,
            $ownedEmail,
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
        $email = $this->normalizedEmailExpression();
        $customerType = $this->quotedCustomerMorphClass();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s) '
                . 'WHERE %s IS NULL AND %s IS NULL AND %s = %s AND %s = %s AND %s IS NOT NULL',
                $wrappedIndex,
                $wrappedTable,
                $email,
                $grammar->wrap('owner_type'),
                $grammar->wrap('owner_id'),
                $grammar->wrap('contactable_type'),
                $customerType,
                $grammar->wrap('type'),
                DB::connection()->getPdo()->quote(self::EMAIL_TYPE),
                $email,
            ));

            return;
        }

        $globalEmail = $this->mysqlScopedEmailExpression($email, $customerType, owned: false);

        DB::statement(sprintf(
            'CREATE UNIQUE INDEX %s ON %s ((%s))',
            $wrappedIndex,
            $wrappedTable,
            $globalEmail,
        ));
    }

    private function normalizedEmailExpression(): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $normalized = $grammar->wrap('normalized_value');
        $value = $grammar->wrap('value');

        return sprintf(
            "NULLIF(LOWER(TRIM(COALESCE(NULLIF(%s, ''), %s))), '')",
            $normalized,
            $value,
        );
    }

    private function mysqlScopedEmailExpression(string $email, string $customerType, bool $owned): string
    {
        $grammar = DB::connection()->getQueryGrammar();
        $ownerType = $grammar->wrap('owner_type');
        $ownerId = $grammar->wrap('owner_id');
        $contactableType = $grammar->wrap('contactable_type');
        $type = $grammar->wrap('type');
        $ownerCondition = $owned
            ? sprintf('%s IS NOT NULL AND %s IS NOT NULL', $ownerType, $ownerId)
            : sprintf('%s IS NULL AND %s IS NULL', $ownerType, $ownerId);

        return sprintf(
            "CASE WHEN %s AND %s = %s AND %s = %s THEN CAST(%s AS CHAR(320)) ELSE NULL END",
            $ownerCondition,
            $contactableType,
            $customerType,
            $type,
            DB::connection()->getPdo()->quote(self::EMAIL_TYPE),
            $email,
        );
    }

    private function customerMorphClass(): string
    {
        return (new Customer)->getMorphClass();
    }

    private function quotedCustomerMorphClass(): string
    {
        return DB::connection()->getPdo()->quote($this->customerMorphClass());
    }

    private function indexPrefix(string $tableName): string
    {
        return str_replace(['.', '-', ' '], '_', $tableName);
    }
};
