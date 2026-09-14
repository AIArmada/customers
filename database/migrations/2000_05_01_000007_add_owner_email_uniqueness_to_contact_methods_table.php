<?php

declare(strict_types=1);

use AIArmada\CommerceSupport\Support\ConnectionDriver;
use AIArmada\Customers\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string EMAIL_TYPE = 'email';

    public function up(): void
    {
        $tableName = (string) config('contacting.database.tables.contact_methods', 'contact_methods');

        $driver = ConnectionDriver::name(Schema::getConnection());

        $ownedIndexName = $this->indexPrefix($tableName) . '_customer_email_owner_unique';
        $globalIndexName = $this->indexPrefix($tableName) . '_customer_email_global_unique';

        $this->createOwnedIndex($tableName, $ownedIndexName, $driver);
        $this->createGlobalIndex($tableName, $globalIndexName, $driver);
    }

    private function createOwnedIndex(string $tableName, string $indexName, string $driver): void
    {

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedIndex = $grammar->wrap($indexName);
        $ownerType = $grammar->wrap('owner_type');
        $ownerId = $grammar->wrap('owner_id');
        $email = $this->normalizedEmailExpression();
        $customerType = $this->quotedCustomerMorphClass();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (%s, %s, %s) '
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

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedIndex = $grammar->wrap($indexName);
        $email = $this->normalizedEmailExpression();
        $customerType = $this->quotedCustomerMorphClass();

        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(sprintf(
                'CREATE UNIQUE INDEX %s ON %s (%s) '
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
            'CASE WHEN %s AND %s = %s AND %s = %s THEN CAST(%s AS CHAR(320)) ELSE NULL END',
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
