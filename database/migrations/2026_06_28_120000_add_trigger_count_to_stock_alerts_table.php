<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_alerts', function (Blueprint $table): void {
            $table->unsignedInteger('trigger_count')->default(0)->after('last_triggered_at');
        });
    }

    public function down(): void
    {
        Schema::table('stock_alerts', function (Blueprint $table): void {
            $table->dropColumn('trigger_count');
        });
    }
};
