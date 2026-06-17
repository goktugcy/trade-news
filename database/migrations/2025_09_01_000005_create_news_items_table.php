<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->nullable()->constrained('news_sources')->nullOnDelete();
            $table->string('title');
            $table->text('summary')->nullable();
            $table->longText('content')->nullable();
            $table->string('url', 1024)->nullable();
            $table->string('image_url', 1024)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('market', 16)->nullable();         // BIST | NASDAQ | null = global
            $table->string('sentiment', 16)->nullable();       // positive|neutral|negative
            $table->decimal('sentiment_score', 5, 4)->nullable(); // [-1, 1]
            $table->unsignedTinyInteger('importance_score')->default(0); // 0-100
            $table->boolean('is_matched')->default(false);     // news→stock matching done?
            $table->string('hash', 64)->unique();              // dedupe key
            $table->timestamp('created_at')->nullable();

            $table->index('published_at');
            $table->index('market');
            $table->index(['is_matched', 'sentiment']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_items');
    }
};
