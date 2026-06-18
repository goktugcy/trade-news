<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('sector');
            $table->decimal('market_cap', 20, 2)->nullable()->after('industry');
            $table->string('website')->nullable()->after('market_cap');
            $table->text('description')->nullable()->after('website');
            $table->jsonb('company_profile')->nullable()->after('description');
            $table->timestamp('profile_synced_at')->nullable()->after('company_profile');
        });
    }

    public function down(): void
    {
        Schema::table('stocks', function (Blueprint $table) {
            $table->dropColumn(['industry', 'market_cap', 'website', 'description', 'company_profile', 'profile_synced_at']);
        });
    }
};
