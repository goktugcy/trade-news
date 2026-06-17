<?php

declare(strict_types=1);

use App\Models\NotificationRule;
use App\Models\User;

it('creates a notification rule', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/alerts', [
        'name' => 'BIST positive only',
        'interval_minutes' => 15,
        'markets' => ['BIST'],
        'sentiments' => ['positive'],
        'only_watchlist' => true,
        'min_importance' => 40,
        'is_active' => true,
    ])->assertRedirect();

    $rule = $user->notificationRules()->first();

    expect($rule)->not->toBeNull()
        ->and($rule->interval_minutes)->toBe(15)
        ->and($rule->markets)->toBe(['BIST'])
        ->and($rule->min_importance)->toBe(40);
});

it('rejects an invalid interval', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from('/alerts')
        ->post('/alerts', ['name' => 'Bad', 'interval_minutes' => 7])
        ->assertSessionHasErrors('interval_minutes');
});

it('lets a user update their own rule', function () {
    $user = User::factory()->create();
    $rule = NotificationRule::factory()->for($user)->create(['name' => 'Old']);

    $this->actingAs($user)->put("/alerts/{$rule->id}", [
        'name' => 'New name',
        'interval_minutes' => 60,
    ])->assertRedirect();

    expect($rule->fresh()->name)->toBe('New name');
});

it('forbids editing another user\'s rule', function () {
    $rule = NotificationRule::factory()->for(User::factory())->create();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)
        ->put("/alerts/{$rule->id}", ['name' => 'Hijack', 'interval_minutes' => 60])
        ->assertForbidden();
});

it('deletes a rule', function () {
    $user = User::factory()->create();
    $rule = NotificationRule::factory()->for($user)->create();

    $this->actingAs($user)->delete("/alerts/{$rule->id}")->assertRedirect();

    expect(NotificationRule::find($rule->id))->toBeNull();
});
