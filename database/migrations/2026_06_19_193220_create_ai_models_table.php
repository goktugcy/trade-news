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
        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_provider_id')->constrained('api_providers')->cascadeOnDelete();
            $table->string('name');
            $table->string('model');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('max_output_tokens')->default(160);
            $table->decimal('temperature', 3, 2)->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();

            $table->unique(['api_provider_id', 'model']);
            $table->index(['is_active', 'api_provider_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_models');
    }
};
