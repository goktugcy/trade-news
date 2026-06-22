<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_providers', function (Blueprint $table): void {
            // Per-day request/failure counters (reset when daily_counts_on rolls over)
            // + a rolling average latency, for the admin provider-usage view.
            $table->unsignedInteger('daily_request_count')->default(0)->after('consecutive_successes');
            $table->unsignedInteger('daily_failure_count')->default(0)->after('daily_request_count');
            $table->date('daily_counts_on')->nullable()->after('daily_failure_count');
            $table->unsignedInteger('avg_latency_ms')->nullable()->after('last_latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('api_providers', function (Blueprint $table): void {
            $table->dropColumn(['daily_request_count', 'daily_failure_count', 'daily_counts_on', 'avg_latency_ms']);
        });
    }
};
