<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // In-app notification inbox (distinct from app_notifications, which is the
        // Telegram delivery log).
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category', 32);          // alert | news | system | provider | sync
            $table->string('type', 64);              // machine type, e.g. price_above, provider_status
            $table->string('title');
            $table->text('body')->nullable();
            $table->jsonb('data')->nullable();
            $table->string('action_url', 1024)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');
    }
};
