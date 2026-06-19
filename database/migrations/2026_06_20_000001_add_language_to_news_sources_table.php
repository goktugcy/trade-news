<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_sources', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('market');
        });

        // Backfill the language from the source market: BIST → Turkish, NASDAQ → English.
        DB::table('news_sources')->where('market', 'BIST')->update(['language' => 'tr']);
        DB::table('news_sources')->where('market', 'NASDAQ')->update(['language' => 'en']);
        DB::table('news_sources')->whereNull('language')->update(['language' => 'en']);
    }

    public function down(): void
    {
        Schema::table('news_sources', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
