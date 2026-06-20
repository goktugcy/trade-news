<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ai_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained('stocks')->cascadeOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained('ai_models')->nullOnDelete();
            $table->string('signal', 16); // bullish | neutral | bearish
            $table->unsignedTinyInteger('confidence')->default(0); // 0-100
            $table->string('horizon', 32)->nullable();
            $table->decimal('estimated_price_low', 20, 6)->nullable();
            $table->decimal('estimated_price_high', 20, 6)->nullable();
            $table->decimal('estimated_price', 20, 6)->nullable();
            $table->string('currency', 8)->nullable();
            $table->text('summary')->nullable();
            $table->jsonb('drivers')->nullable();
            $table->jsonb('risks')->nullable();
            $table->text('disclaimer')->nullable();
            $table->jsonb('input_snapshot')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['stock_id', 'generated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_ai_analyses');
    }
};
