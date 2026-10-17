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

        if (! in_array($driver, ['pgsql', 'sqlite'], true)) {
            throw new RuntimeException(sprintf(
                'Customer email identity migration cannot run on unsupported database driver [%s].',
                $driver,
            ));
        }

        $ownedIndexName = $this->indexPrefix($tableName) . '_customer_email_owner_unique';
        $globalIndexName = $this->indexPrefix($tableName) . '_customer_email_global_unique';

        $this->createOwnedIndex($tableName, $ownedIndexName);
        $this->createGlobalIndex($tableName, $globalIndexName);
    }

    private function createOwnedIndex(string $tableName, string $indexName): void
    {

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedIndex = $grammar->wrap($indexName);
        $ownerType = $grammar->wrap('owner_type');
        $ownerId = $grammar->wrap('owner_id');
        $email = $this->normalizedEmailExpression();
        $customerType = $this->quotedCustomerMorphClass();

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
    }

    private function createGlobalIndex(string $tableName, string $indexName): void
    {

        $grammar = DB::connection()->getQueryGrammar();
        $wrappedTable = $grammar->wrapTable($tableName);
        $wrappedIndex = $grammar->wrap($indexName);
        $email = $this->normalizedEmailExpression();
        $customerType = $this->quotedCustomerMorphClass();

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
