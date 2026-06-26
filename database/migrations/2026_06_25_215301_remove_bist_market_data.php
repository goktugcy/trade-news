<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $removedMarket = 'BIST';
        $supportedMarket = 'NASDAQ';

        $sourceIds = DB::table('news_sources')
            ->where('market', $removedMarket)
            ->orWhereIn('key', ['kap', 'kap-rss', 'bloomberg-ht', 'foreks', 'investing-tr'])
            ->pluck('id');

        $newsItemIds = DB::table('news_items')
            ->where('market', $removedMarket)
            ->when(
                $sourceIds->isNotEmpty(),
                fn ($query) => $query
                    ->orWhereIn('source_id', $sourceIds)
                    ->orWhereIn(
                        'id',
                        DB::table('news_item_sources')
                            ->whereIn('news_source_id', $sourceIds)
                            ->select('news_item_id'),
                    ),
            )
            ->pluck('id');

        if ($newsItemIds->isNotEmpty()) {
            DB::table('news_items')->whereIn('id', $newsItemIds)->delete();
        }

        if ($sourceIds->isNotEmpty()) {
            DB::table('news_sources')->whereIn('id', $sourceIds)->delete();
        }

        DB::table('stocks')->where('market', $removedMarket)->delete();
        DB::table('sync_runs')
            ->where('type', 'bist100_quotes')
            ->orWhere('provider_key', 'rapidapi-bist100')
            ->delete();

        DB::table('api_providers')
            ->select(['id', 'key', 'markets'])
            ->orderBy('id')
            ->get()
            ->each(function (object $provider) use ($removedMarket): void {
                $markets = $this->decodeMarkets($provider->markets);

                if (
                    in_array($provider->key, ['rapidapi-bist100', 'kap'], true)
                    || in_array($removedMarket, $markets, true)
                ) {
                    DB::table('api_providers')->where('id', $provider->id)->delete();
                }
            });

        DB::table('notification_rules')
            ->select(['id', 'markets'])
            ->orderBy('id')
            ->get()
            ->each(function (object $rule) use ($removedMarket): void {
                $markets = $this->decodeMarkets($rule->markets);

                if (! in_array($removedMarket, $markets, true)) {
                    return;
                }

                $remainingMarkets = array_values(array_diff($markets, [$removedMarket]));

                if ($remainingMarkets === []) {
                    DB::table('notification_rules')->where('id', $rule->id)->delete();

                    return;
                }

                DB::table('notification_rules')
                    ->where('id', $rule->id)
                    ->update(['markets' => json_encode($remainingMarkets)]);
            });

        DB::table('user_data_preferences')
            ->select(['id', 'preferred_markets'])
            ->orderBy('id')
            ->get()
            ->each(function (object $preference) use ($removedMarket, $supportedMarket): void {
                $markets = $this->decodeMarkets($preference->preferred_markets);

                if (! in_array($removedMarket, $markets, true)) {
                    return;
                }

                $remainingMarkets = array_values(array_diff($markets, [$removedMarket]));

                DB::table('user_data_preferences')
                    ->where('id', $preference->id)
                    ->update([
                        'preferred_markets' => json_encode($remainingMarkets ?: [$supportedMarket]),
                    ]);
            });
    }

    public function down(): void
    {
        // Removed market data cannot be reconstructed safely.
    }

    /**
     * @return array<int, string>
     */
    private function decodeMarkets(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values(array_filter($decoded, 'is_string'))
            : [];
    }
};
