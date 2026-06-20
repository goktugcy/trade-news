<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NewsItem;
use App\Services\Translation\ContentTranslationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class TranslateNewsItemJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $newsItemId,
        public readonly string $locale,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(ContentTranslationService $translations): void
    {
        $item = NewsItem::query()
            ->with(['source:id,language', 'translations' => fn ($query) => $query->where('locale', $this->locale)])
            ->find($this->newsItemId);

        if ($item === null) {
            return;
        }

        $translations->translateNewsItem($item, $this->locale);
    }
}
