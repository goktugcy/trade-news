<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Models\NewsSource;
use App\Models\User;
use App\Models\UserDataPreference;
use Illuminate\Support\Facades\App;

it('shows onboarding props on the dashboard until preferences are completed', function () {
    $user = User::factory()->create();
    NewsSource::factory()->create(['name' => 'Market Wire']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Dashboard')
            ->where('onboarding.completed', false)
            ->where('onboarding.should_show', true)
            ->where('locale', 'en')
            ->has('onboardingOptions.sources', 1)
            ->has('onboardingOptions.markets', 2));
});

it('saves locale markets and source preferences from onboarding', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $enabledSource = NewsSource::factory()->create(['language' => 'en']);
    $disabledSource = NewsSource::factory()->create(['language' => 'tr']);

    $this->actingAs($user)
        ->put(route('onboarding.preferences.update'), [
            'locale' => 'tr',
            'preferred_markets' => [Market::BIST->value],
            'news_sources' => [
                ['id' => $enabledSource->id, 'enabled' => true],
                ['id' => $disabledSource->id, 'enabled' => false],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $user->refresh();
    $preference = $user->dataPreference;

    expect($user->locale)->toBe('tr')
        ->and($preference)->toBeInstanceOf(UserDataPreference::class)
        ->and($preference->preferred_markets)->toBe([Market::BIST->value])
        ->and($preference->onboarding_completed_at)->not->toBeNull()
        ->and($user->disabledNewsSources()->pluck('news_source_id')->all())->toBe([$disabledSource->id]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('onboarding.completed', true)
            ->where('onboarding.should_show', false)
            ->where('locale', 'tr'));
});

it('sets the laravel locale from the authenticated profile locale', function () {
    $user = User::factory()->create(['locale' => 'tr']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(App::getLocale())->toBe('tr');
});
