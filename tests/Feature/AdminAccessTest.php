<?php

declare(strict_types=1);

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('blocks non-admins from the admin panel', function () {
    $user = User::factory()->create(['is_admin' => false]);

    $this->actingAs($user)->get('/admin')->assertForbidden();
});

it('allows admins into the admin dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->get('/admin')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/Dashboard')->has('stats'));
});

it('lets an admin create a stock', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->post('/admin/stocks', [
        'symbol' => 'XYZ',
        'name' => 'Example Corp',
        'market' => 'NASDAQ',
        'currency' => 'USD',
        'aliases' => ['XYZ', 'Example'],
        'is_active' => true,
    ])->assertRedirect();

    $this->assertDatabaseHas('stocks', ['symbol' => 'XYZ', 'market' => 'NASDAQ']);
});

it('prevents an admin from demoting themselves', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->patch("/admin/users/{$admin->id}/admin")->assertRedirect();

    expect($admin->fresh()->is_admin)->toBeTrue();
});
