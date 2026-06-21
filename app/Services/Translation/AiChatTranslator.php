<?php

declare(strict_types=1);

namespace App\Services\Translation;

use App\Models\AiModel;
use App\Services\Ai\AiProviderClientFactory;
use App\Services\Ai\HuggingFaceEndpointClient;
use RuntimeException;

class AiChatTranslator implements TextTranslatorInterface
{
    public function __construct(private readonly AiProviderClientFactory $clients) {}

    public function isConfigured(AiModel $model): bool
    {
        if (! $model->isHealthy()) {
            return false;
        }

        $client = $this->clients->make($model->provider);

        if ($client === null || $client instanceof DeepLTranslator) {
            return false;
        }

        if ($client instanceof HuggingFaceEndpointClient) {
            return trim((string) $model->resolvedEndpoint()) !== '';
        }

        return true;
    }

    /**
     * @param  array<int, string|null>  $texts
     */
    public function translate(AiModel $model, array $texts, string $targetLocale, ?string $sourceLocale = null): ?TranslationResult
    {
        if (! $this->isConfigured($model)) {
            return null;
        }

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

        $client = $this->clients->make($model->provider);

        if ($client === null) {
            return null;
        }

        $result = $client->complete(
            $model,
            $this->translationPayload($indexedTexts, $targetLocale, $sourceLocale),
            $this->instructions($targetLocale),
        );

        if (! $result->successful || $result->text === null) {
            throw new RuntimeException($result->error ?? 'Translation model returned an empty response.');
        }

        $translatedTexts = $this->parseTranslations($result->text);

        if ($translatedTexts === null) {
            throw new RuntimeException('Translation model returned invalid JSON.');
        }

        if (count($translatedTexts) !== count($indexedTexts)) {
            throw new RuntimeException('Translation model returned an unexpected number of translations.');
        }

        $translations = array_fill(0, count($normalizedTexts), null);

        foreach ($translatedTexts as $index => $text) {
            if ($text !== null && isset($positions[$index])) {
                $translations[$positions[$index]] = $text;
            }
        }

        return new TranslationResult($translations, null, $model->provider->key, $result->latencyMs);
    }

    /**
     * @param  array<int, string>  $texts
     */
    private function translationPayload(array $texts, string $targetLocale, ?string $sourceLocale): string
    {
        return (string) json_encode([
            'target_locale' => strtolower($targetLocale),
            'source_locale' => $sourceLocale !== null ? strtolower($sourceLocale) : null,
            'texts' => array_values($texts),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function instructions(string $targetLocale): string
    {
        $language = $this->languageName($targetLocale);

        return <<<PROMPT
You are a financial translation engine. Translate every input text into {$language} ({$targetLocale}).
Always output the translated text in {$language} — never echo the source language.
Preserve stock tickers, company names, numbers, dates, URLs, and financial units unless a natural localized form is clearly required.
Return only valid JSON in this exact shape: {"translations":["translated text 1","translated text 2"]}.
The translations array must have exactly the same length and order as the input texts array. Do not include markdown or any reasoning.
PROMPT;
    }

    private function languageName(string $locale): string
    {
        return match (strtolower($locale)) {
            'tr', 'tr-tr' => 'Turkish',
            'en', 'en-us', 'en-gb' => 'English',
            default => $locale,
        };
    }

    /**
     * @return array<int, string|null>|null
     */
    private function parseTranslations(string $text): ?array
    {
        $decoded = $this->decodeJson($text);

        if (! is_array($decoded)) {
            return null;
        }

        $translations = null;

        if (isset($decoded['translations']) && is_array($decoded['translations'])) {
            $translations = $decoded['translations'];
        } elseif (array_is_list($decoded)) {
            $translations = $decoded;
        }

        if (! is_array($translations)) {
            return null;
        }

        return collect($translations)
            ->map(fn (mixed $translation): ?string => is_string($translation) ? trim($translation) : null)
            ->values()
            ->all();
    }

    /**
     * @return array<mixed>|null
     */
    private function decodeJson(string $text): ?array
    {
        $text = trim($text);

        if (preg_match('/```(?:json)?\s*(.*?)```/is', $text, $match) === 1) {
            $text = trim($match[1]);
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $object = $this->between($text, '{', '}');
        $array = $this->between($text, '[', ']');

        foreach ([$object, $array] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $decoded = json_decode($candidate, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function between(string $text, string $open, string $close): ?string
    {
        $start = strpos($text, $open);
        $end = strrpos($text, $close);

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, $end - $start + 1);
    }
}
