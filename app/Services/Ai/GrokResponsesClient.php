<?php

declare(strict_types=1);

namespace App\Services\Ai;

class GrokResponsesClient extends OpenAiResponsesClient
{
    protected function defaultBaseUrl(): string
    {
        return 'https://api.x.ai/v1';
    }
}
