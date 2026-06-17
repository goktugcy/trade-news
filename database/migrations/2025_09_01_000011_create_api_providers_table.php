<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_providers', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // finnhub, twelvedata, kap, synthetic
            $table->string('name');
            $table->string('type', 16);                // market_data | news
            $table->string('status', 16)->default('unknown'); // operational|degraded|down|unknown
            $table->boolean('is_active')->default(true);
            $table->string('base_url')->nullable();
            $table->unsignedSmallInteger('priority')->default(100); // lower = preferred
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_latency_ms')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_providers');
    }
};
