<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('timeframe', 8);            // 1m, 5m, 15m, 1h, 1d
            $table->decimal('open', 18, 6);
            $table->decimal('high', 18, 6);
            $table->decimal('low', 18, 6);
            $table->decimal('close', 18, 6);
            $table->decimal('volume', 20, 2)->default(0);
            $table->timestamp('price_at');
            $table->timestamp('created_at')->nullable();

            // One candle per (stock, timeframe, time) — lets us upsert idempotently.
            $table->unique(['stock_id', 'timeframe', 'price_at']);
            $table->index(['stock_id', 'timeframe', 'price_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_prices');
    }
};
