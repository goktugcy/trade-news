<?php

declare(strict_types=1);

use App\Models\Stock;
use App\Models\User;
use App\Models\Watchlist;

it('requires authentication to manage a watchlist', function () {
    $this->post('/watchlist', ['stock_id' => 1])->assertRedirect(route('login'));
});

it('adds a stock to the watchlist', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();

    $this->actingAs($user)
        ->post('/watchlist', ['stock_id' => $stock->id])
        ->assertRedirect();

    expect($user->watchlist()->where('stock_id', $stock->id)->exists())->toBeTrue();
});

it('does not duplicate a stock already on the watchlist', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->create();

    $this->actingAs($user)->post('/watchlist', ['stock_id' => $stock->id]);
    $this->actingAs($user)->post('/watchlist', ['stock_id' => $stock->id]);

    expect($user->watchlist()->where('stock_id', $stock->id)->count())->toBe(1);
});

it('toggles the per-stock alert flag', function () {
    $user = User::factory()->create();
    $item = Watchlist::factory()->for($user)->create(['alerts_enabled' => true]);

    $this->actingAs($user)
        ->patch("/watchlist/{$item->id}/alert")
        ->assertRedirect();

    expect($item->fresh()->alerts_enabled)->toBeFalse();
});

it('prevents toggling another user\'s watchlist entry', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $item = Watchlist::factory()->for($owner)->create();

    $this->actingAs($other)
        ->patch("/watchlist/{$item->id}/alert")
        ->assertForbidden();
});

it('removes a stock from the watchlist', function () {
    $user = User::factory()->create();
    $item = Watchlist::factory()->for($user)->create();

    $this->actingAs($user)
        ->delete("/watchlist/{$item->id}")
        ->assertRedirect();

    expect(Watchlist::find($item->id))->toBeNull();
});
