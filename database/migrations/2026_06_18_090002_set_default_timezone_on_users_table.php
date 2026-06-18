<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->default('Europe/Istanbul')->change();
        });

        // Move rows still on the old default to the new one.
        DB::table('users')->where('timezone', 'UTC')->update(['timezone' => 'Europe/Istanbul']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->change();
        });
    }
};
