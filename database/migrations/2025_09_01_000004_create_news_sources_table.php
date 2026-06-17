<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // slug, e.g. "kap", "finnhub"
            $table->string('name');
            $table->string('provider')->nullable();    // which driver fetches it
            $table->string('market', 16)->nullable();  // BIST | NASDAQ | null = both
            $table->string('homepage_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_sources');
    }
};
