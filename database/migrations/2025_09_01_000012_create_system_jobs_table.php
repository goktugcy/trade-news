<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Heartbeat / run log for scheduled jobs — powers the admin "System Health" view.
        Schema::create('system_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();           // e.g. fetch:prices
            $table->string('status', 16)->default('running'); // running|success|failed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('message')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_jobs');
    }
};
