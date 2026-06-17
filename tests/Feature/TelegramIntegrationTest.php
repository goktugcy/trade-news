<?php

declare(strict_types=1);

use App\Models\TelegramIntegration;
use App\Models\User;
use App\Services\Telegram\TelegramConnectionService;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => Http::fake());

it('generates a connection code for the user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/settings/telegram/code')->assertRedirect();

    $integration = $user->telegramIntegration()->first();
    expect($integration->connection_code)->not->toBeNull()
        ->and($integration->code_expires_at)->not->toBeNull();
});

it('links a chat id when the bot receives a valid code', function () {
    $user = User::factory()->create();
    $integration = app(TelegramConnectionService::class)->generateCode($user);

    $this->postJson('/telegram/webhook/'.config('tradenews.telegram.webhook_secret'), [
        'message' => [
            'chat' => ['id' => 987654321],
            'from' => ['username' => 'trader_jane'],
            'text' => '/start '.$integration->connection_code,
        ],
    ])->assertOk();

    $fresh = $integration->fresh();
    expect($fresh->chat_id)->toBe('987654321')
        ->and($fresh->is_enabled)->toBeTrue()
        ->and($fresh->connection_code)->toBeNull();
});

it('rejects the webhook with a wrong secret', function () {
    $this->postJson('/telegram/webhook/wrong-secret', ['message' => []])
        ->assertForbidden();
});

it('does not link an expired or unknown code', function () {
    $integration = app(TelegramConnectionService::class)->linkChat('NOPE1234', '111', null);

    expect($integration)->toBeNull();
});

it('toggles alert delivery only when connected', function () {
    $user = User::factory()->create();
    TelegramIntegration::factory()->for($user)->connected()->create(['is_enabled' => false]);

    $this->actingAs($user)->post('/settings/telegram/toggle')->assertRedirect();

    expect($user->telegramIntegration->fresh()->is_enabled)->toBeTrue();
});
