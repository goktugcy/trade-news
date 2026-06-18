<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Audit log of every provider status transition.
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_provider_id')->constrained('api_providers')->cascadeOnDelete();
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->string('reason')->nullable();
            $table->jsonb('context')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['api_provider_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
