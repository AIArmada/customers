<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addOwnerFilterIndex(
            (string) config('customers.database.tables.customers', 'customers'),
            'status',
        );
        $this->addOwnerFilterIndex(
            (string) config('customers.database.tables.segments', 'customer_segments'),
            'is_active',
        );
    }

    private function addOwnerFilterIndex(string $tableName, string $filterColumn): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        $columns = ['owner_type', 'owner_id', $filterColumn];

        foreach ($columns as $column) {
            if (Schema::hasColumn($tableName, $column)) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Customer owner index migration cannot run because [%s] is missing column [%s].',
                $tableName,
                $column,
            ));
        }

        $indexName = "{$tableName}_owner_{$filterColumn}_index";

        if (Schema::hasIndex($tableName, $indexName) || Schema::hasIndex($tableName, $columns)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName): void {
            $table->index($columns, $indexName);
        });
    }
};
