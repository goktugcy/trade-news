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
        Schema::create('news_item_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('news_item_id')->constrained('news_items')->cascadeOnDelete();
            $table->string('locale', 2);
            $table->string('title')->nullable();
            $table->text('summary')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('provider')->nullable();
            $table->timestamps();

            $table->unique(['news_item_id', 'locale']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_item_translations');
    }
};
