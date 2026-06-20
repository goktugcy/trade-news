<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\AiModel;
use App\Services\Ai\AiCompletionResult;
use App\Services\Ai\AiProviderClientInterface;
use App\Services\Ai\Concerns\MeasuresAiRequests;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeepLTranslator implements AiProviderClientInterface, TextTranslatorInterface
{
    use MeasuresAiRequests;

    private const DEFAULT_BASE_URL = 'https://api-free.deepl.com/v2';

    public function isConfigured(AiModel $model): bool
    {
        $authKey = trim((string) $model->provider?->api_key);

        return $authKey !== '';
    }

    public function complete(AiModel $model, string $input, string $instructions): AiCompletionResult
    {
        try {
            $result = $this->translate($model, ['OK'], 'tr', 'en');
        } catch (Throwable $exception) {
            return new AiCompletionResult(false, error: mb_substr($exception->getMessage(), 0, 500));
        }

        $text = trim((string) ($result?->texts[0] ?? ''));

        return new AiCompletionResult(
            $text !== '',
            $text !== '' ? $text : null,
            $result?->latencyMs,
            $text === '' ? 'Empty DeepL response.' : null,
        );
    }

    /**
     * @param  array<int, string|null>  $texts
     */
    public function translate(AiModel $model, array $texts, string $targetLocale, ?string $sourceLocale = null): ?TranslationResult
    {
        if (! $this->isConfigured($model)) {
            return null;
        }

        $authKey = trim((string) $model->provider?->api_key);

        $normalizedTexts = collect($texts)
            ->map(fn (?string $text): string => trim((string) $text))
            ->values()
            ->all();

        $indexedTexts = [];
        $positions = [];

        foreach ($normalizedTexts as $index => $text) {
            if ($text === '') {
                continue;
            }

            $positions[] = $index;
            $indexedTexts[] = $text;
        }

        if ($indexedTexts === []) {
            return null;
        }

        $payload = [
            'text' => $indexedTexts,
            'target_lang' => $this->deeplLanguage($targetLocale, true),
        ];

        if ($sourceLocale !== null && $sourceLocale !== '') {
            $payload['source_lang'] = $this->deeplLanguage($sourceLocale, false);
        }

        $startedAt = $this->startedAt();

        $response = $this->client($authKey)
            ->post($this->translateUrl($model), $payload)
            ->throw()
            ->json('translations');

        if (! is_array($response)) {
            return null;
        }

        $translations = array_fill(0, count($normalizedTexts), null);

        collect($response)
            ->map(fn (mixed $translation): ?string => is_array($translation) && is_string($translation['text'] ?? null)
                ? $translation['text']
                : null)
            ->values()
            ->each(function (?string $text, int $index) use (&$translations, $positions): void {
                if ($text !== null && isset($positions[$index])) {
                    $translations[$positions[$index]] = $text;
                }
            });

        $detectedSourceLanguage = is_array($response[0] ?? null) && is_string($response[0]['detected_source_language'] ?? null)
            ? $response[0]['detected_source_language']
            : null;

        return new TranslationResult($translations, $detectedSourceLanguage, 'deepl', $this->latencyMs($startedAt));
    }

    private function client(string $authKey): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(15)
            ->withHeaders([
                'Authorization' => 'DeepL-Auth-Key '.$authKey,
            ]);
    }

    private function translateUrl(AiModel $model): string
    {
        $baseUrl = rtrim($model->resolvedEndpoint() ?: self::DEFAULT_BASE_URL, '/');

        if (str_ends_with($baseUrl, '/translate')) {
            return $baseUrl;
        }

        return $baseUrl.'/translate';
    }

    private function deeplLanguage(string $locale, bool $target): string
    {
        return match (strtolower($locale)) {
            'tr', 'tr-tr' => 'TR',
            'en', 'en-us', 'en-gb' => $target ? 'EN-US' : 'EN',
            default => strtoupper($locale),
        };
    }
}
