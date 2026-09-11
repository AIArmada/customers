<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addCustomerFirstIndex(
            (string) config('customers.database.tables.segment_customer', 'customer_segment_customer'),
            'customer_segment_customer_customer_id_index',
        );
        $this->addCustomerFirstIndex(
            (string) config('customers.database.tables.group_members', 'customer_group_members'),
            'customer_group_members_customer_id_index',
        );
    }

    private function addCustomerFirstIndex(string $tableName, string $indexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'customer_id')) {
            throw new RuntimeException(sprintf(
                'Customer pivot index migration cannot run because [%s] is missing column [customer_id].',
                $tableName,
            ));
        }

        if (Schema::hasIndex($tableName, $indexName)
            || Schema::hasIndex($tableName, ['customer_id'])) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName): void {
            $table->index('customer_id', $indexName);
        });
    }
};
