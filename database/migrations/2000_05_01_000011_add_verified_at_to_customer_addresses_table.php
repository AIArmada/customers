<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('customers.database.tables.addresses', 'customer_addresses'), function (Blueprint $table): void {
            $table->timestampTz('verified_at')->nullable();
        });

        $tableName = config('customers.database.tables.addresses', 'customer_addresses');

        DB::statement("UPDATE {$tableName} SET verified_at = updated_at WHERE is_verified = true AND verified_at IS NULL");
    }
};
