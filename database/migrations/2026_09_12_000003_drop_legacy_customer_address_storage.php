<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config(
            'customers.database.tables.addresses',
            'customer_' . 'addresses',
        );
        $schema = Schema::getConnection()->getSchemaBuilder();

        if (! $schema->hasTable($tableName)) {
            return;
        }

        $legacyColumns = [
            'customer_id',
            'type',
            'label',
            'recipient_name',
            'company',
            'line1',
            'line2',
            'city',
            'state',
            'postcode',
            'country_code',
            'country',
            'is_default_billing',
            'is_default_shipping',
            'is_verified',
            'verified_at',
            'coordinates',
            'metadata',
        ];
        $existingColumns = array_values(array_filter(
            $legacyColumns,
            static fn (string $column): bool => $schema->hasColumn($tableName, $column),
        ));

        if ($existingColumns === []) {
            return;
        }

        $indexes = array_values(array_filter(
            $schema->getIndexes($tableName),
            static fn (array $index): bool => ! $index['primary'],
        ));

        if ($indexes !== []) {
            Schema::table($tableName, function (Blueprint $table) use ($indexes): void {
                foreach ($indexes as $index) {
                    $table->dropIndex($index['name']);
                }
            });
        }

        $schema->drop($tableName);
    }
};
