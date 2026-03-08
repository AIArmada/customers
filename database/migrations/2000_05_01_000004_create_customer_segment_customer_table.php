<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('customers.database.tables.segment_customer', 'customer_segment_customer'), function (Blueprint $table): void {
            $table->foreignUuid('segment_id');
            $table->foreignUuid('customer_id');

            $table->timestamps();

            $table->primary(['segment_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('customers.database.tables.segment_customer', 'customer_segment_customer'));
    }
};
