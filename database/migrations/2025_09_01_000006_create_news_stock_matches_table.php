<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_stock_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained('stocks')->cascadeOnDelete();
            $table->string('match_type', 16);     // symbol | name | alias | keyword
            $table->string('matched_term');        // the literal token that matched
            $table->decimal('confidence', 4, 3)->default(1.0);
            $table->timestamp('created_at')->nullable();

            $table->unique(['news_item_id', 'stock_id']);
            $table->index('stock_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_stock_matches');
    }
};
