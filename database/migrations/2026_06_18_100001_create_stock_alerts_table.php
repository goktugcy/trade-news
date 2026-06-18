<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User-defined condition alerts (price/volume/%/news) on a stock.
        Schema::create('stock_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);              // AlertType
            $table->decimal('threshold', 20, 6)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('cooldown_minutes')->default(60);
            $table->timestamp('last_triggered_at')->nullable();
            $table->boolean('notify_in_app')->default(true);
            $table->boolean('notify_telegram')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'type']);
            $table->index(['user_id', 'stock_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_alerts');
    }
};
