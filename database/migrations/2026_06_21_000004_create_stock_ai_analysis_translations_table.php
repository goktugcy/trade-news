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
        if (Schema::hasTable('stock_ai_analysis_translations')) {
            return;
        }

        Schema::create('stock_ai_analysis_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_ai_analysis_id')->constrained('stock_ai_analyses')->cascadeOnDelete();
            $table->string('locale', 2);
            $table->text('summary')->nullable();
            $table->jsonb('drivers')->nullable();
            $table->jsonb('risks')->nullable();
            $table->text('disclaimer')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('provider')->nullable();
            $table->timestamps();

            $table->unique(['stock_ai_analysis_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_ai_analysis_translations');
    }
};
