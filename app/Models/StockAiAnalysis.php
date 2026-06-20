<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StockSignal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cached AI-generated forecast/signal for a stock.
 *
 * @property int $id
 * @property int $stock_id
 * @property int|null $ai_model_id
 * @property StockSignal $signal
 * @property int $confidence
 * @property string|null $horizon
 * @property float|null $estimated_price_low
 * @property float|null $estimated_price_high
 * @property float|null $estimated_price
 * @property string|null $currency
 * @property string|null $summary
 * @property array<int, string>|null $drivers
 * @property array<int, string>|null $risks
 * @property string|null $disclaimer
 * @property array<string, mixed>|null $input_snapshot
 * @property Carbon|null $generated_at
 * @property Carbon|null $expires_at
 * @property-read Stock $stock
 * @property-read AiModel|null $aiModel
 */
class StockAiAnalysis extends Model
{
    public const DISCLAIMER = 'Bu analiz yapay zeka tarafından otomatik üretilmiş tahmini bir senaryo/sinyaldir; yatırım tavsiyesi değildir. Doğruluk, getiri veya fiyat gerçekleşmesi garanti edilmez; sorumluluk kabul edilmemektedir.';

    protected $table = 'stock_ai_analyses';

    protected $fillable = [
        'stock_id',
        'ai_model_id',
        'signal',
        'confidence',
        'horizon',
        'estimated_price_low',
        'estimated_price_high',
        'estimated_price',
        'currency',
        'summary',
        'drivers',
        'risks',
        'disclaimer',
        'input_snapshot',
        'generated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'signal' => StockSignal::class,
            'confidence' => 'integer',
            'estimated_price_low' => 'float',
            'estimated_price_high' => 'float',
            'estimated_price' => 'float',
            'drivers' => 'array',
            'risks' => 'array',
            'input_snapshot' => 'array',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }

    /**
     * @return BelongsTo<AiModel, $this>
     */
    public function aiModel(): BelongsTo
    {
        return $this->belongsTo(AiModel::class);
    }

    public function isStale(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    /** @param  Builder<StockAiAnalysis>  $query */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('generated_at')->orderByDesc('id');
    }
}
