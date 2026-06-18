<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every original source for an article. A merged article (one news_items
        // row) keeps one row here per source it was reported by.
        Schema::create('news_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->foreignId('news_source_id')->constrained('news_sources')->cascadeOnDelete();
            $table->string('url', 1024)->nullable();
            $table->timestamp('published_at')->nullable();
            // Exact per-source fingerprint — guarantees we ingest each source once.
            $table->string('original_hash', 64)->unique();
            $table->timestamp('created_at')->nullable();

            $table->unique(['news_item_id', 'news_source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_item_sources');
    }
};
