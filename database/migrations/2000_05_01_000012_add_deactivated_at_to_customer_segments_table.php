<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('customers.database.tables.segments', 'customer_segments'), function (Blueprint $table): void {
            $table->timestampTz('deactivated_at')->nullable();
        });
    }
};
