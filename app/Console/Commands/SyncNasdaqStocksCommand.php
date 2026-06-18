<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\Market;
use App\Enums\ProviderType;
use App\Models\ApiProvider;
use App\Models\Stock;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JsonException;

class SyncNasdaqStocksCommand extends Command
{
    /** @var array<int, string> */
    private const DEFAULT_TYPES = ['Common Stock', 'ADR', 'REIT', 'Tracking Stk'];

    protected $signature = 'tradenews:sync-nasdaq-stocks
        {--type=* : Finnhub symbol type to import; defaults to equity-like stock types}
        {--all-types : Import every XNAS symbol type}
        {--limit= : Maximum number of symbols to import}
        {--deactivate-missing : Mark imported-market symbols absent from the filtered feed inactive}';

    protected $description = 'Sync NASDAQ stock symbols from Finnhub into the stocks catalog';

    public function handle(): int
    {
        $provider = $this->finnhubProvider();
        $baseUrl = $provider?->base_url ?: (string) config('tradenews.market_data.providers.finnhub.base_url');
        $apiKey = trim((string) $provider?->api_key);

        if ($apiKey === '') {
            $this->error('Finnhub provider API key is not configured.');

            return self::FAILURE;
        }

        try {
            $response = Http::baseUrl($baseUrl)
                ->connectTimeout(3)
                ->timeout(30)
                ->retry([500, 1000], throw: false)
                ->get('/stock/symbol', [
                    'exchange' => 'US',
                    'token' => $apiKey,
                ]);
        } catch (ConnectionException $exception) {
            $this->error("Finnhub connection failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if ($response->failed()) {
            $this->error("Finnhub symbol sync failed with HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            $this->error('Finnhub symbol sync returned an invalid payload.');

            return self::FAILURE;
        }

        $rows = $this->rows($payload);

        if ($this->limit() !== null) {
            $rows = array_slice($rows, 0, $this->limit());
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            Stock::query()->upsert(
                $chunk,
                ['market', 'symbol'],
                ['name', 'exchange', 'currency', 'aliases', 'is_active', 'updated_at'],
            );
        }

        if ($this->option('deactivate-missing') && $rows !== []) {
            Stock::query()
                ->market(Market::NASDAQ)
                ->whereNotIn('symbol', array_column($rows, 'symbol'))
                ->update(['is_active' => false]);
        }

        $this->info('Synced '.count($rows).' NASDAQ stock symbol(s).');

        return self::SUCCESS;
    }

    /** @return array<int, string> */
    private function types(): array
    {
        $types = $this->option('type');

        if ($types === []) {
            return self::DEFAULT_TYPES;
        }

        return array_values(array_filter(array_map(
            fn (?string $type): string => trim((string) $type),
            $types,
        )));
    }

    /**
     * @param  array<int|string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $payload): array
    {
        $rows = [];
        $types = $this->types();

        foreach ($payload as $row) {
            if (! is_array($row)) {
                continue;
            }

            if (($row['mic'] ?? null) !== 'XNAS') {
                continue;
            }

            if (! $this->option('all-types') && ! in_array($row['type'] ?? null, $types, true)) {
                continue;
            }

            $normalized = $this->normalizeRow($row);

            if ($normalized === null) {
                continue;
            }

            $rows[(string) $normalized['symbol']] = $normalized;
        }

        ksort($rows);

        return array_values($rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function normalizeRow(array $row): ?array
    {
        $symbol = Str::upper(trim((string) (($row['displaySymbol'] ?? null) ?: ($row['symbol'] ?? ''))));
        $name = trim((string) ($row['description'] ?? ''));

        if ($symbol === '' || $name === '') {
            return null;
        }

        return [
            'symbol' => $symbol,
            'name' => $name,
            'market' => Market::NASDAQ->value,
            'exchange' => Market::NASDAQ->label(),
            'currency' => (string) (($row['currency'] ?? null) ?: Market::NASDAQ->currency()),
            'sector' => null,
            'aliases' => $this->aliases($symbol, $name),
            'keywords' => '[]',
            'is_active' => true,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ];
    }

    private function aliases(string $symbol, string $name): string
    {
        try {
            return json_encode(array_values(array_unique([$symbol, $name])), JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return '[]';
        }
    }

    private function limit(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        return max(1, (int) $limit);
    }

    private function finnhubProvider(): ?ApiProvider
    {
        return ApiProvider::query()
            ->where('key', 'finnhub')
            ->where('type', ProviderType::MarketData->value)
            ->where('is_active', true)
            ->first();
    }
}
