<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            // Normalized-title fingerprint used to detect the same story across
            // different sources (non-unique: many sources share one fingerprint).
            $table->string('normalized_hash', 64)->nullable()->after('hash')->index();
            // How many original sources have been merged into this article.
            $table->unsignedInteger('source_count')->default(1)->after('normalized_hash');
        });
    }

    public function down(): void
    {
        Schema::table('news_items', function (Blueprint $table) {
            $table->dropColumn(['normalized_hash', 'source_count']);
        });
    }
};
