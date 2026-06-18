<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->jsonb('markets')->nullable()->after('type');        // null = all markets
            $table->jsonb('capabilities')->nullable()->after('markets'); // e.g. ["quotes","candles","news","profiles"]
            $table->boolean('auto_recovery')->default(true)->after('priority');
            $table->unsignedSmallInteger('consecutive_failures')->default(0)->after('auto_recovery');
            $table->unsignedSmallInteger('consecutive_successes')->default(0)->after('consecutive_failures');
        });
    }

    public function down(): void
    {
        Schema::table('api_providers', function (Blueprint $table) {
            $table->dropColumn(['markets', 'capabilities', 'auto_recovery', 'consecutive_failures', 'consecutive_successes']);
        });
    }
};
