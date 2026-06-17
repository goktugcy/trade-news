<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Application notification *log*. (Laravel's own framework notifications
        // table is not used here — this is our delivery audit trail.)
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('news_item_id')->nullable()->constrained('news_items')->nullOnDelete();
            $table->foreignId('notification_rule_id')->nullable()->constrained('notification_rules')->nullOnDelete();
            $table->string('channel', 32)->default('telegram');
            $table->string('status', 16)->default('queued'); // queued|sent|failed
            $table->string('title');
            $table->text('body')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
            // Prevent re-notifying the same user about the same news item.
            $table->unique(['user_id', 'news_item_id', 'channel'], 'app_notifications_user_news_channel_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
