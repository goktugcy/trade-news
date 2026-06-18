<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserDataPreference;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the default data preference', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/settings/data')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Data')
            ->where('preference.auto_refresh_seconds', UserDataPreference::DEFAULT_AUTO_REFRESH_SECONDS)
            ->where('dataPreferences.auto_refresh_seconds', UserDataPreference::DEFAULT_AUTO_REFRESH_SECONDS));
});

it('updates the browser auto refresh preference and shares it with inertia', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->put('/settings/data', ['auto_refresh_seconds' => 15])
        ->assertRedirect();

    expect($user->dataPreference()->first()?->auto_refresh_seconds)->toBe(15);

    $this->actingAs($user)
        ->get('/settings/data')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('preference.auto_refresh_seconds', 15)
            ->where('dataPreferences.auto_refresh_seconds', 15));
});
