<?php

declare(strict_types=1);

use App\Models\User;

it('lets a user update their timezone', function () {
    $user = User::factory()->create(['timezone' => 'Europe/Istanbul']);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => $user->name,
        'email' => $user->email,
        'timezone' => 'America/New_York',
    ])->assertRedirect();

    expect($user->fresh()->timezone)->toBe('America/New_York');
});

it('rejects an invalid timezone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => 'Mars/Phobos',
        ])
        ->assertSessionHasErrors('timezone');
});

it('exposes the timezone list to the profile page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn ($page) => $page->component('settings/Profile')->has('timezones'));
});
