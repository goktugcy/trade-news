<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiModel;

interface AiProviderClientInterface
{
    public function complete(AiModel $model, string $input, string $instructions): AiCompletionResult;
}
