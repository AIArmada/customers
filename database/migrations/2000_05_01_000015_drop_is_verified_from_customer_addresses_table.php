<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('customers.database.tables.addresses', 'customer_addresses');

        if (Schema::hasTable($tableName) && Schema::hasColumn($tableName, 'is_verified')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->dropColumn('is_verified');
            });
        }
    }
};
