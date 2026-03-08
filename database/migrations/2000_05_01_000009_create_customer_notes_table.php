<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('customers.database.tables.notes', 'customer_notes'), function (Blueprint $table): void {
            $jsonColumnType = config('customers.database.json_column_type', 'json');

            $table->uuid('id')->primary();

            // Owner (for multi-tenancy)
            $table->nullableUuidMorphs('owner');

            $table->foreignUuid('customer_id');

            // Who created the note
            $table->foreignUuid('created_by')->nullable();

            // Note content
            $table->text('content');

            // Visibility
            $table->boolean('is_internal')->default(true);
            $table->boolean('is_pinned')->default(false);

            $table->{$jsonColumnType}('metadata')->nullable();

            $table->timestamps();

            $table->index(['customer_id', 'is_pinned']);
            $table->index(['customer_id', 'is_internal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('customers.database.tables.notes', 'customer_notes'));
    }
};
