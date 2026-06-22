<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_index_memberships', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('index_key'); // StockIndex enum value: nasdaq100 | sp500
            $table->boolean('is_current')->default(true);
            $table->timestamp('added_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['stock_id', 'index_key']);
            $table->index(['index_key', 'is_current']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_index_memberships');
    }
};
