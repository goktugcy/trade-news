<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_task_settings', function (Blueprint $table) {
            $table->id();
            $table->string('task', 32)->unique();
            $table->boolean('enabled')->default(false);
            $table->foreignId('active_ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('fallback_behavior', 32)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_task_settings');
    }
};
