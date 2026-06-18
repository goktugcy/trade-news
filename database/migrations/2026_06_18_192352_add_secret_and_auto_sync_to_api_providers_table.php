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
            $table->text('api_key')->nullable()->after('base_url');
            $table->boolean('auto_sync_stocks')->default(false)->after('is_active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->dropColumn(['api_key', 'auto_sync_stocks']);
        });
    }
};
