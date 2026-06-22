<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            // Stored "EXCHANGE:SYMBOL" for the TradingView widget (e.g. NASDAQ:AAPL).
            // Nullable — the model accessor computes it on the fly when absent.
            $table->string('tradingview_symbol')->nullable()->after('exchange');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table): void {
            $table->dropColumn('tradingview_symbol');
        });
    }
};
