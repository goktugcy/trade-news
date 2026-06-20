<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->string('task', 32)->nullable()->after('model');
            $table->string('runtime', 40)->nullable()->after('task');
            $table->string('endpoint_url', 1024)->nullable()->after('runtime');
            $table->string('status', 16)->default('unknown')->after('is_active');
            $table->timestamp('last_checked_at')->nullable()->after('status');
            $table->unsignedInteger('last_latency_ms')->nullable()->after('last_checked_at');
            $table->text('last_error')->nullable()->after('last_latency_ms');
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_error');
            $table->unsignedInteger('consecutive_successes')->default(0)->after('consecutive_failures');

            $table->index(['task', 'is_active']);
        });

        // The same underlying model can power more than one task (e.g. Qwen for
        // both summary and stock analysis), so scope uniqueness by task too.
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropUnique('ai_models_api_provider_id_model_unique');
            $table->unique(['api_provider_id', 'task', 'model']);
        });
    }

    public function down(): void
    {
        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropUnique(['api_provider_id', 'task', 'model']);
            $table->unique(['api_provider_id', 'model']);
        });

        Schema::table('ai_models', function (Blueprint $table) {
            $table->dropIndex(['task', 'is_active']);
            $table->dropColumn([
                'task', 'runtime', 'endpoint_url', 'status', 'last_checked_at',
                'last_latency_ms', 'last_error', 'consecutive_failures', 'consecutive_successes',
            ]);
        });
    }
};
