<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // History of data-synchronization runs (NASDAQ list, company profiles).
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);              // nasdaq_list | company_profiles
            $table->string('provider_key')->nullable();
            $table->string('status', 16)->default('running'); // running | success | failed
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['type', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
