<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StockAiAnalysisTranslationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $stock_ai_analysis_id
 * @property string $locale
 * @property string|null $summary
 * @property array<int, string>|null $drivers
 * @property array<int, string>|null $risks
 * @property string|null $disclaimer
 * @property Carbon|null $generated_at
 * @property string|null $provider
 * @property-read StockAiAnalysis $stockAiAnalysis
 */
class StockAiAnalysisTranslation extends Model
{
    /** @use HasFactory<StockAiAnalysisTranslationFactory> */
    use HasFactory;

    protected $fillable = [
        'stock_ai_analysis_id',
        'locale',
        'summary',
        'drivers',
        'risks',
        'disclaimer',
        'generated_at',
        'provider',
    ];

    protected function casts(): array
    {
        return [
            'drivers' => 'array',
            'risks' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<StockAiAnalysis, $this>
     */
    public function stockAiAnalysis(): BelongsTo
    {
        return $this->belongsTo(StockAiAnalysis::class);
    }
}
