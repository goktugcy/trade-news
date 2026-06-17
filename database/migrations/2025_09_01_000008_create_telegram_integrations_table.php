<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('chat_id')->nullable()->index();
            $table->string('telegram_username')->nullable();
            // One-time code the user pastes to the bot to link their chat.
            $table->string('connection_code', 16)->nullable()->unique();
            $table->timestamp('code_expires_at')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_integrations');
    }
};
