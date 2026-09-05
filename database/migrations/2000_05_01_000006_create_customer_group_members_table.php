<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        commerce_schema_create_if_missing(config('customers.database.tables.group_members', 'customer_group_members'), function (Blueprint $table): void {
            $table->foreignUuid('group_id');
            $table->foreignUuid('customer_id');

            // Role in the group
            $table->string('role')->default('member'); // admin, member

            $table->timestampTz('joined_at')->nullable();
            $table->timestampsTz();

            $table->primary(['group_id', 'customer_id']);
        });
    }
};
