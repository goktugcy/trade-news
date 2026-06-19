<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-user like (1) / dislike (-1) on a news item — one row per (user, item).
        Schema::create('news_item_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->smallInteger('value');
            $table->timestamps();

            $table->unique(['user_id', 'news_item_id']);
        });

        // Per-user saved/bookmarked news items.
        Schema::create('saved_news_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'news_item_id']);
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_news_items');
        Schema::dropIfExists('news_item_reactions');
    }
};
