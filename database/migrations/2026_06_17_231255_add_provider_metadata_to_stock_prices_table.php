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
        Schema::table('stock_prices', function (Blueprint $table) {
            $table->string('provider_key')->nullable();
            $table->string('source_kind', 16)->default('candle');
            $table->index(['stock_id', 'timeframe', 'provider_key', 'source_kind', 'price_at'], 'stock_prices_provider_lookup_index');
            $table->index(['provider_key', 'source_kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_prices', function (Blueprint $table) {
            $table->dropIndex('stock_prices_provider_lookup_index');
            $table->dropIndex(['provider_key', 'source_kind']);
            $table->dropColumn(['provider_key', 'source_kind']);
        });
    }
};
