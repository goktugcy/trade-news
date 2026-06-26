<?php

use App\Http\Controllers\Settings\DataPreferenceController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\TelegramController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::patch('settings/locale', [ProfileController::class, 'updateLocale'])->name('profile.locale');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/data', [DataPreferenceController::class, 'edit'])->name('data-preferences.edit');
    Route::put('settings/data', [DataPreferenceController::class, 'update'])->name('data-preferences.update');

    // Telegram integration settings.
    Route::get('settings/telegram', [TelegramController::class, 'show'])->name('telegram.show');
    Route::post('settings/telegram/code', [TelegramController::class, 'generateCode'])->name('telegram.code');
    Route::post('settings/telegram/toggle', [TelegramController::class, 'toggle'])->name('telegram.toggle');
    Route::delete('settings/telegram', [TelegramController::class, 'disconnect'])->name('telegram.disconnect');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
