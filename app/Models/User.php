<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_admin
 * @property string $timezone
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read UserDataPreference|null $dataPreference
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'timezone'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
        ];
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    /**
     * @return HasMany<Watchlist, $this>
     */
    public function watchlist(): HasMany
    {
        return $this->hasMany(Watchlist::class)->orderBy('position');
    }

    /**
     * Stocks this user follows (through the watchlist pivot).
     *
     * @return BelongsToMany<Stock, $this>
     */
    public function watchedStocks(): BelongsToMany
    {
        return $this->belongsToMany(Stock::class, 'watchlists')
            ->withPivot(['alerts_enabled', 'position'])
            ->withTimestamps();
    }

    /**
     * @return HasOne<TelegramIntegration, $this>
     */
    public function telegramIntegration(): HasOne
    {
        return $this->hasOne(TelegramIntegration::class);
    }

    /**
     * @return HasOne<UserDataPreference, $this>
     */
    public function dataPreference(): HasOne
    {
        return $this->hasOne(UserDataPreference::class);
    }

    /**
     * @return HasMany<NotificationRule, $this>
     */
    public function notificationRules(): HasMany
    {
        return $this->hasMany(NotificationRule::class);
    }

    /**
     * Delivery log of alerts sent to this user (our own table, not the
     * framework's `notifications` morphMany provided by Notifiable).
     *
     * @return HasMany<Notification, $this>
     */
    public function alertLogs(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * In-app notification inbox.
     *
     * @return HasMany<UserNotification, $this>
     */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class)->latest('id');
    }

    /**
     * Condition-based stock alerts (price/volume/news).
     *
     * @return HasMany<StockAlert, $this>
     */
    public function stockAlerts(): HasMany
    {
        return $this->hasMany(StockAlert::class)->latest('id');
    }
}
