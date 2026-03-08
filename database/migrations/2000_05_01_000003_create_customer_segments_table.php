<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('customers.database.tables.segments', 'customer_segments'), function (Blueprint $table): void {
            $jsonColumnType = config('customers.database.json_column_type', 'json');

            $table->uuid('id')->primary();

            // Owner (for multi-tenancy)
            $table->nullableUuidMorphs('owner');

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            // Type
            $table->string('type')->default('custom'); // loyalty, behavior, demographic, custom

            // Automatic segment conditions (JSON rules)
            $table->{$jsonColumnType}('conditions')->nullable();
            $table->boolean('is_automatic')->default(true);

            // Priority for pricing (higher = more important)
            $table->integer('priority')->default(0);

            // Status
            $table->boolean('is_active')->default(true);

            $table->{$jsonColumnType}('metadata')->nullable();

            $table->timestamps();

            // Indexes
            $table->index(['is_active', 'priority']);
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('customers.database.tables.segments', 'customer_segments'));
    }
};
