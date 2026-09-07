<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = (string) config('customers.database.tables.customers', 'customers');

        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'person_id')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->uuid('person_id')->nullable();
            });
        }

        if (! Schema::hasIndex($tableName, 'customers_person_id_index')) {
            Schema::table($tableName, function (Blueprint $table): void {
                $table->index('person_id', 'customers_person_id_index');
            });
        }
    }
};
