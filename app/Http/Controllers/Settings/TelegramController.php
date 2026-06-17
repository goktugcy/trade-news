<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TelegramController extends Controller
{
    public function __construct(
        private readonly TelegramConnectionService $connections,
    ) {}

    public function show(Request $request): Response
    {
        $integration = $request->user()->telegramIntegration;

        return Inertia::render('settings/Telegram', [
            'integration' => $integration ? [
                'is_connected' => $integration->isConnected(),
                'is_enabled' => $integration->is_enabled,
                'telegram_username' => $integration->telegram_username,
                'connection_code' => $integration->connection_code,
                'code_expires_at' => $integration->code_expires_at?->toIso8601String(),
                'connected_at' => $integration->connected_at?->toIso8601String(),
            ] : null,
            'bot' => [
                'username' => config('tradenews.telegram.username'),
                'configured' => ! empty(config('tradenews.telegram.token')),
            ],
        ]);
    }

    /**
     * Issue a fresh one-time connection code.
     */
    public function generateCode(Request $request): RedirectResponse
    {
        $this->connections->generateCode($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Connection code generated. Send it to the bot.']);

        return back();
    }

    /**
     * Enable / disable alert delivery (only meaningful once connected).
     */
    public function toggle(Request $request): RedirectResponse
    {
        $integration = $this->connections->integrationFor($request->user());

        if (! $integration->isConnected()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Connect your Telegram account first.']);

            return back();
        }

        $integration->update(['is_enabled' => ! $integration->is_enabled]);

        return back();
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $this->connections->disconnect($request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Telegram disconnected.']);

        return back();
    }
}
