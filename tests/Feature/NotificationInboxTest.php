<?php

declare(strict_types=1);

use App\Enums\NotificationCategory;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\TelegramIntegration;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notification\NotificationCenter;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

it('creates an in-app notification for a user', function () {
    $user = User::factory()->create();

    app(NotificationCenter::class)->toUser($user, NotificationCategory::System, 'welcome', 'Hello', 'Body');

    $n = $user->userNotifications()->first();
    expect($n)->not->toBeNull()
        ->and($n->category)->toBe(NotificationCategory::System)
        ->and($n->isRead())->toBeFalse();
});

it('also queues Telegram when requested and the integration is active', function () {
    Queue::fake();
    $user = User::factory()->create();
    TelegramIntegration::factory()->for($user)->connected()->create();

    app(NotificationCenter::class)->toUser($user, NotificationCategory::Alert, 'price_above', 'AAPL', 'crossed 200', telegram: true);

    Queue::assertPushed(SendTelegramNotificationJob::class);
    expect($user->userNotifications()->count())->toBe(1);
});

it('fans out admin notifications to admins only', function () {
    $admin = User::factory()->admin()->create();
    User::factory()->create(); // non-admin

    $created = app(NotificationCenter::class)->toAdmins(NotificationCategory::Provider, 'status', 'Finnhub down');

    expect($created)->toHaveCount(1)
        ->and(UserNotification::where('user_id', $admin->id)->count())->toBe(1);
});

it('renders the inbox and filters by category + unread', function () {
    $user = User::factory()->create();
    UserNotification::factory()->for($user)->create(['category' => NotificationCategory::Alert]);
    UserNotification::factory()->for($user)->read()->create(['category' => NotificationCategory::News]);

    $this->actingAs($user)
        ->get('/notifications?category=alert&unread=1')
        ->assertOk()
        ->assertInertia(fn (Assert $p) => $p->component('notifications/Index')->has('notifications.data', 1));
});

it('exposes unread count via the JSON endpoint and shared props', function () {
    $user = User::factory()->create();
    UserNotification::factory()->for($user)->count(3)->create();

    $this->actingAs($user)->getJson('/notifications/unread')->assertOk()->assertJsonPath('count', 3);

    $this->actingAs($user)->get('/dashboard')
        ->assertInertia(fn (Assert $p) => $p->where('notifications.unread_count', 3));
});

it('marks one and all notifications read', function () {
    $user = User::factory()->create();
    $one = UserNotification::factory()->for($user)->create();
    UserNotification::factory()->for($user)->count(2)->create();

    $this->actingAs($user)->patch("/notifications/{$one->id}/read")->assertRedirect();
    expect($one->fresh()->isRead())->toBeTrue();

    $this->actingAs($user)->post('/notifications/read-all')->assertRedirect();
    expect($user->userNotifications()->unread()->count())->toBe(0);
});

it('forbids acting on another user notification', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $n = UserNotification::factory()->for($owner)->create();

    $this->actingAs($other)->patch("/notifications/{$n->id}/read")->assertForbidden();
    $this->actingAs($other)->delete("/notifications/{$n->id}")->assertForbidden();
});
