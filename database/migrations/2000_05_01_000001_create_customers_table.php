<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('customers.database.tables.customers', 'customers'), function (Blueprint $table): void {
            $jsonColumnType = config('customers.database.json_column_type', commerce_json_column_type('customers', 'jsonb'));

            $table->uuid('id')->primary();

            // Owner (for multi-tenancy)
            $table->nullableUuidMorphs('owner');

            // Link to User model
            $table->foreignUuid('user_id')->nullable();

            // Basic info
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->index();
            $table->string('phone')->nullable()->index();
            $table->string('company')->nullable();

            // Status
            $table->string('status')->default('active');

            // Preferences
            $table->boolean('accepts_marketing')->default(true);
            $table->boolean('is_guest')->default(false);

            // Lifecycle
            $table->timestampTz('registered_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('marketing_consented_at')->nullable();
            $table->timestampTz('marketing_revoked_at')->nullable();

            // Metadata
            $table->{$jsonColumnType}('metadata')->nullable();

            $table->timestampsTz();

            // Indexes
            $table->index(['status', 'accepts_marketing']);
            $table->index('is_guest');
            $table->index('activated_at');
            $table->index('suspended_at');
        });
    }

};
