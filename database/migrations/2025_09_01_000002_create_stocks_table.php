<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->id();
            $table->string('symbol');
            $table->string('name');
            $table->string('market', 16);          // BIST | NASDAQ
            $table->string('exchange')->nullable();
            $table->string('currency', 8)->default('USD');
            $table->string('logo_url')->nullable();
            $table->string('sector')->nullable();
            // Search aliases used by the news matcher (symbols, brand names, etc.).
            $table->jsonb('aliases')->nullable();
            $table->jsonb('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['market', 'symbol']);
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
