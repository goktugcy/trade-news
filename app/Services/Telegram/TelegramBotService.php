<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\NewsItem;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Thin wrapper over the Telegram Bot HTTP API.
 *
 * @see https://core.telegram.org/bots/api
 */
class TelegramBotService
{
    public function __construct(
        private readonly ?string $token,
        private readonly string $apiUrl = 'https://api.telegram.org',
    ) {}

    public function isConfigured(): bool
    {
        return ! empty($this->token);
    }

    /**
     * Send a (HTML-formatted) message to a chat. Returns true on success.
     */
    public function sendMessage(string $chatId, string $text): bool
    {
        if (! $this->isConfigured()) {
            Log::warning('Telegram not configured; message skipped.', ['chat_id' => $chatId]);

            return false;
        }

        $response = $this->client()->post('/sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => false,
        ]);

        if ($response->failed() || ! ($response->json('ok') === true)) {
            Log::warning('Telegram sendMessage failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            return false;
        }

        return true;
    }

    public function setWebhook(string $url, string $secretToken): bool
    {
        return (bool) $this->client()->post('/setWebhook', [
            'url' => $url,
            'secret_token' => $secretToken,
            'allowed_updates' => ['message'],
        ])->json('ok');
    }

    public function deleteWebhook(): bool
    {
        return (bool) $this->client()->post('/deleteWebhook')->json('ok');
    }

    /**
     * Render the Telegram alert body for a matched news item.
     */
    public function formatNewsAlert(NewsItem $news): string
    {
        $symbols = $news->stocks->pluck('symbol')->implode(', ');
        $market = $news->market !== null ? $news->market->value : '—';
        $publishedAt = $news->published_at?->timezone(config('app.timezone'))->format('H:i d.m.Y') ?? '—';
        $source = $news->source !== null ? $news->source->name : 'News Provider';
        $emoji = $news->sentiment?->emoji() ?? '🚨';

        $lines = [
            "{$emoji} <b>".e($symbols !== '' ? $symbols : 'Market').' News Alert</b>',
            '',
            '<b>Title:</b> '.e($news->title),
            "<b>Market:</b> {$market}",
            '<b>Related stocks:</b> '.e($symbols !== '' ? $symbols : '—'),
            "<b>Published at:</b> {$publishedAt}",
            '<b>Source:</b> '.e($source),
        ];

        if ($news->url) {
            $lines[] = '';
            $lines[] = '<a href="'.e($news->url).'">Read more →</a>';
        }

        return implode("\n", $lines);
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl("{$this->apiUrl}/bot{$this->token}")
            ->timeout(10)
            ->retry(2, 250);
    }
}
