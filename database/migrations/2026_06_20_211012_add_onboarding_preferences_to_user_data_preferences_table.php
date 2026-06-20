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
        Schema::table('user_data_preferences', function (Blueprint $table) {
            $table->jsonb('preferred_markets')->nullable()->after('auto_refresh_seconds');
            $table->timestamp('onboarding_completed_at')->nullable()->after('preferred_markets');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_data_preferences', function (Blueprint $table) {
            $table->dropColumn(['preferred_markets', 'onboarding_completed_at']);
        });
    }
};
