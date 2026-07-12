<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('customers.database.tables.addresses', 'customer_addresses'), function (Blueprint $table): void {
            $jsonColumnType = commerce_json_column_type('customers', 'jsonb');

            $table->uuid('id')->primary();

            // Owner (for multi-tenancy)
            $table->nullableUuidMorphs('owner');

            $table->foreignUuid('customer_id');

            // Type
            $table->string('type')->default('both'); // billing, shipping, both

            // Label
            $table->string('label')->nullable(); // "Home", "Office", etc.

            // Recipient info
            $table->string('recipient_name')->nullable();
            $table->string('company')->nullable();
            $table->string('phone')->nullable()->after('company');

            // Address fields
            $table->string('line1');
            $table->string('line2')->nullable();
            $table->string('city');
            $table->string('state')->nullable();
            $table->string('postcode');
            $table->string('country_code', 2)->default('MY'); // ISO 3166-1 alpha-2
            $table->string('country')->nullable(); // Full country name

            // Default flags
            $table->boolean('is_default_billing')->default(false);
            $table->boolean('is_default_shipping')->default(false);

            // Verification
            $table->boolean('is_verified')->default(false);
            $table->timestampTz('verified_at')->nullable();
            $table->{$jsonColumnType}('coordinates')->nullable(); // lat/lng

            $table->{$jsonColumnType}('metadata')->nullable();

            $table->timestampsTz();

            // Indexes
            $table->index(['customer_id', 'type']);
            $table->index(['customer_id', 'is_default_billing']);
            $table->index(['customer_id', 'is_default_shipping']);
        });
    }
};
