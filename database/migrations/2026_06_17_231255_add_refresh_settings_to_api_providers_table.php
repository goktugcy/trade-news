<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->unsignedSmallInteger('refresh_interval_minutes')->default(5);
            $table->unsignedSmallInteger('fetch_limit')->default(50);
            $table->timestamp('last_fetched_at')->nullable()->index();
            $table->index(['type', 'is_active', 'priority']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->dropIndex(['type', 'is_active', 'priority']);
            $table->dropIndex(['last_fetched_at']);
            $table->dropColumn(['refresh_interval_minutes', 'fetch_limit', 'last_fetched_at']);
        });
    }
};
