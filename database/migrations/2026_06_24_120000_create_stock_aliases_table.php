<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Denormalized, deterministic matching index. Rebuilt from each stock's
        // canonical sources (symbol, name, suffix-stripped name, aliases JSON,
        // curated extras) by StockAliasService — not edited directly.
        Schema::create('stock_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->string('alias');            // original display form (e.g. "Apple Inc.")
            $table->string('normalized');       // lowercased, punctuation-stripped (e.g. "apple inc")
            $table->string('kind', 16);         // ticker | name | alias
            $table->decimal('confidence', 4, 3)->default(0.9);
            $table->timestamps();

            $table->unique(['stock_id', 'normalized']);
            $table->index('normalized');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_aliases');
    }
};
