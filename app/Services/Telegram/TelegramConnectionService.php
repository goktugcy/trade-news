<?php

declare(strict_types=1);

namespace App\Services\Telegram;

use App\Models\TelegramIntegration;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Manages the "connect your Telegram" handshake:
 *
 *   1. User requests a one-time code (generateCode).
 *   2. User sends the code to the bot; Telegram webhook calls linkChat().
 *   3. chat_id is stored and alerts can be enabled.
 */
class TelegramConnectionService
{
    private const CODE_TTL_MINUTES = 30;

    public function integrationFor(User $user): TelegramIntegration
    {
        return TelegramIntegration::query()->firstOrCreate(['user_id' => $user->id]);
    }

    /**
     * Issue a fresh connection code for the user.
     */
    public function generateCode(User $user): TelegramIntegration
    {
        $integration = $this->integrationFor($user);

        $integration->forceFill([
            'connection_code' => $this->uniqueCode(),
            'code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
        ])->save();

        return $integration;
    }

    /**
     * Link a Telegram chat to whichever user owns the (unexpired) code.
     */
    public function linkChat(string $code, string $chatId, ?string $username = null): ?TelegramIntegration
    {
        $integration = TelegramIntegration::query()
            ->where('connection_code', Str::upper(trim($code)))
            ->where(function ($q): void {
                $q->whereNull('code_expires_at')->orWhere('code_expires_at', '>', Carbon::now());
            })
            ->first();

        if ($integration === null) {
            return null;
        }

        $integration->forceFill([
            'chat_id' => $chatId,
            'telegram_username' => $username,
            'connection_code' => null,
            'code_expires_at' => null,
            'is_enabled' => true,
            'connected_at' => now(),
        ])->save();

        return $integration;
    }

    public function disconnect(User $user): void
    {
        $this->integrationFor($user)->forceFill([
            'chat_id' => null,
            'telegram_username' => null,
            'connection_code' => null,
            'code_expires_at' => null,
            'is_enabled' => false,
            'connected_at' => null,
        ])->save();
    }

    private function uniqueCode(): string
    {
        do {
            $code = Str::upper(Str::random(8));
        } while (TelegramIntegration::query()->where('connection_code', $code)->exists());

        return $code;
    }
}
