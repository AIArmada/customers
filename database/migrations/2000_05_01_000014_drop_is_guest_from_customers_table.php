<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('customers.database.tables.customers', 'customers');

        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'is_guest')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropIndex('customers_is_guest_index');
            });

            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('is_guest');
            });
        }
    }
};
