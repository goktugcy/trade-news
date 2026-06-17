<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('My alerts');
            // Cadence in minutes (matches NotificationInterval backing value).
            $table->unsignedSmallInteger('interval_minutes')->default(60);
            // null markets array => all markets.
            $table->jsonb('markets')->nullable();
            // null sentiments array => any sentiment.
            $table->jsonb('sentiments')->nullable();
            $table->boolean('only_watchlist')->default(true);
            $table->unsignedTinyInteger('min_importance')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_dispatched_at')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'interval_minutes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
