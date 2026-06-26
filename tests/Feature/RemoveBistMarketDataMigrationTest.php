<?php

declare(strict_types=1);

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\NewsItem;
use App\Models\NewsSource;
use App\Models\NotificationRule;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

it('removes legacy market data and normalizes user market preferences', function () {
    $user = User::factory()->create();
    $stock = Stock::factory()->nasdaq()->create();
    $source = NewsSource::factory()->create(['market' => null]);
    $newsItem = NewsItem::factory()->create([
        'source_id' => $source->id,
        'market' => null,
    ]);
    $provider = ApiProvider::factory()->create([
        'key' => 'legacy-market-provider',
        'type' => ProviderType::MarketData,
        'markets' => ['BIST'],
    ]);
    $legacyRule = NotificationRule::factory()->for($user)->create([
        'markets' => ['BIST'],
    ]);
    $mixedRule = NotificationRule::factory()->for($user)->create([
        'markets' => ['NASDAQ', 'BIST'],
    ]);

    $user->dataPreference()->create([
        'preferred_markets' => ['NASDAQ', 'BIST'],
        'auto_refresh_seconds' => 30,
    ]);

    DB::table('stocks')->where('id', $stock->id)->update(['market' => 'BIST']);
    DB::table('news_sources')->where('id', $source->id)->update(['market' => 'BIST']);
    DB::table('news_items')->where('id', $newsItem->id)->update(['market' => 'BIST']);

    /** @var Migration $migration */
    $migration = require database_path('migrations/2026_06_25_215301_remove_bist_market_data.php');
    $migration->up();

    expect(DB::table('stocks')->where('id', $stock->id)->exists())->toBeFalse()
        ->and(DB::table('news_sources')->where('id', $source->id)->exists())->toBeFalse()
        ->and(DB::table('news_items')->where('id', $newsItem->id)->exists())->toBeFalse()
        ->and(DB::table('api_providers')->where('id', $provider->id)->exists())->toBeFalse()
        ->and(DB::table('notification_rules')->where('id', $legacyRule->id)->exists())->toBeFalse()
        ->and($mixedRule->fresh()?->markets)->toBe([Market::NASDAQ->value])
        ->and($user->dataPreference?->fresh()?->preferred_markets)->toBe([Market::NASDAQ->value]);
});
