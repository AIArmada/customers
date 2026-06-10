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
        Schema::table(config('customers.database.tables.customers', 'customers'), function (Blueprint $table): void {
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('marketing_consented_at')->nullable();
            $table->timestampTz('marketing_revoked_at')->nullable();
        });

        Schema::table(config('customers.database.tables.customers', 'customers'), function (Blueprint $table): void {
            $table->index('activated_at');
            $table->index('suspended_at');
        });

        $tableName = config('customers.database.tables.customers', 'customers');

        DB::statement("UPDATE {$tableName} SET activated_at = created_at WHERE status = 'active' AND activated_at IS NULL");
        DB::statement("UPDATE {$tableName} SET marketing_consented_at = created_at WHERE accepts_marketing = true AND marketing_consented_at IS NULL");
        DB::statement("UPDATE {$tableName} SET verified_at = created_at WHERE status != 'pending_verification' AND verified_at IS NULL");
    }
};
