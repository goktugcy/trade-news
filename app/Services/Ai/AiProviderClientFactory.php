<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\ApiProvider;

class AiProviderClientFactory
{
    public const SUPPORTED_PROVIDER_KEYS = ['openai', 'anthropic', 'gemini', 'grok'];

    public function make(ApiProvider $provider): ?AiProviderClientInterface
    {
        return match ($provider->key) {
            'openai' => new OpenAiResponsesClient,
            'anthropic' => new AnthropicMessagesClient,
            'gemini' => new GeminiGenerateContentClient,
            'grok' => new GrokResponsesClient,
            default => null,
        };
    }

    /**
     * @return array<int, array{key: string, name: string, base_url: string}>
     */
    public static function providerOptions(): array
    {
        return [
            ['key' => 'openai', 'name' => 'OpenAI', 'base_url' => 'https://api.openai.com/v1'],
            ['key' => 'anthropic', 'name' => 'Anthropic', 'base_url' => 'https://api.anthropic.com'],
            ['key' => 'gemini', 'name' => 'Google Gemini', 'base_url' => 'https://generativelanguage.googleapis.com/v1beta'],
            ['key' => 'grok', 'name' => 'Grok / xAI', 'base_url' => 'https://api.x.ai/v1'],
        ];
    }
}
