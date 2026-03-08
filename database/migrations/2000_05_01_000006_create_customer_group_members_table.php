<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('customers.database.tables.group_members', 'customer_group_members'), function (Blueprint $table): void {
            $table->foreignUuid('group_id');
            $table->foreignUuid('customer_id');

            // Role in the group
            $table->string('role')->default('member'); // admin, member

            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->primary(['group_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('customers.database.tables.group_members', 'customer_group_members'));
    }
};
