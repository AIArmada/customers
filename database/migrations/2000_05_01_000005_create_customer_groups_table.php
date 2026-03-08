<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('customers.database.tables.groups', 'customer_groups'), function (Blueprint $table): void {
            $jsonColumnType = config('customers.database.json_column_type', 'json');

            $table->uuid('id')->primary();

            // Owner (for multi-tenancy)
            $table->nullableUuidMorphs('owner');

            $table->string('name');
            $table->text('description')->nullable();

            // Spending limit (in cents, null = unlimited)
            $table->unsignedBigInteger('spending_limit')->nullable();

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_approval')->default(true);

            // Settings
            $table->{$jsonColumnType}('settings')->nullable();
            $table->{$jsonColumnType}('metadata')->nullable();

            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('customers.database.tables.groups', 'customer_groups'));
    }
};
