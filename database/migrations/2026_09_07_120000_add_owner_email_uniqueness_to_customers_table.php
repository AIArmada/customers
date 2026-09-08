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

        $legacyColumns = array_values(array_filter(
            ['email', 'phone'],
            static fn (string $column): bool => Schema::hasColumn($tableName, $column),
        ));

        if ($legacyColumns === []) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($legacyColumns): void {
            $table->dropColumn($legacyColumns);
        });
    }
};
