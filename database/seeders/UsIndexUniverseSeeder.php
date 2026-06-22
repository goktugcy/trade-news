<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Market;
use App\Enums\StockIndex;
use App\Models\Stock;
use App\Models\StockIndexMembership;
use App\Services\Sync\UsIndexUniverseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the NASDAQ-100 + S&P 500 universe from the static fallback config
 * (config/us_index_universe.php) — the same symbol lists the live FMP sync
 * targets. Creates one Stock per symbol (market NASDAQ, USD) plus per-index
 * membership rows, and computes the TradingView ticker.
 *
 * Names: well-known tickers get a curated display name; the long tail is seeded
 * with the symbol as a placeholder and enriched later by `tradenews:sync-profiles`
 * (sector / logo / CEO / market cap / real name). Idempotent and non-destructive:
 * existing names/aliases are preserved on re-run.
 */
class UsIndexUniverseSeeder extends Seeder
{
    public function run(UsIndexUniverseService $universe): void
    {
        $result = $universe->resolve(UsIndexUniverseService::SOURCE_FALLBACK);
        $names = $this->names();
        $now = Carbon::now();

        foreach ($result['symbols'] as $symbol) {
            $stock = Stock::query()->firstOrNew([
                'market' => Market::NASDAQ->value,
                'symbol' => $symbol,
            ]);

            // Only set name/aliases for brand-new rows so a re-run never clobbers
            // real names already filled by StockSeeder or the FMP profile sync.
            if (! $stock->exists) {
                $name = $names[$symbol] ?? $symbol;
                $stock->name = $name;
                $stock->aliases = array_values(array_unique(array_filter([$symbol, $name])));
            }

            $stock->exchange = 'NASDAQ';
            $stock->tradingview_symbol = Stock::tradingViewSymbolFor(Market::NASDAQ, $symbol);
            $stock->currency = 'USD';
            $stock->is_active = true;
            $stock->save();
        }

        $this->seedMemberships(StockIndex::Sp500, $result['sp500_symbols'], $now);
        $this->seedMemberships(StockIndex::Nasdaq100, $result['nasdaq100_symbols'], $now);
    }

    /**
     * @param  array<int, string>  $symbols
     */
    private function seedMemberships(StockIndex $index, array $symbols, Carbon $now): void
    {
        $stockIds = Stock::query()
            ->where('market', Market::NASDAQ->value)
            ->whereIn('symbol', $symbols)
            ->pluck('id');

        foreach ($stockIds as $id) {
            $membership = StockIndexMembership::query()->firstOrNew([
                'stock_id' => $id,
                'index_key' => $index->value,
            ]);

            if (! $membership->exists) {
                $membership->added_at = $now;
            }

            $membership->is_current = true;
            $membership->removed_at = null;
            $membership->save();
        }
    }

    /**
     * Curated display names for widely-followed tickers. Anything not listed is
     * seeded with its symbol and enriched by the FMP profile sync.
     *
     * @return array<string, string>
     */
    private function names(): array
    {
        return [
            'AAPL' => 'Apple Inc.', 'MSFT' => 'Microsoft Corporation', 'NVDA' => 'NVIDIA Corporation',
            'AMZN' => 'Amazon.com Inc.', 'GOOGL' => 'Alphabet Inc. (Class A)', 'GOOG' => 'Alphabet Inc. (Class C)',
            'META' => 'Meta Platforms Inc.', 'TSLA' => 'Tesla Inc.', 'AVGO' => 'Broadcom Inc.',
            'AMD' => 'Advanced Micro Devices', 'NFLX' => 'Netflix Inc.', 'INTC' => 'Intel Corporation',
            'ADBE' => 'Adobe Inc.', 'CSCO' => 'Cisco Systems Inc.', 'QCOM' => 'Qualcomm Inc.',
            'TXN' => 'Texas Instruments', 'AMAT' => 'Applied Materials', 'MU' => 'Micron Technology',
            'INTU' => 'Intuit Inc.', 'ORCL' => 'Oracle Corporation', 'IBM' => 'IBM',
            'CRM' => 'Salesforce Inc.', 'NOW' => 'ServiceNow Inc.', 'PLTR' => 'Palantir Technologies',
            'PANW' => 'Palo Alto Networks', 'CRWD' => 'CrowdStrike Holdings', 'SNPS' => 'Synopsys Inc.',
            'CDNS' => 'Cadence Design Systems', 'LRCX' => 'Lam Research', 'KLAC' => 'KLA Corporation',
            'MRVL' => 'Marvell Technology', 'ADI' => 'Analog Devices', 'NXPI' => 'NXP Semiconductors',
            'PYPL' => 'PayPal Holdings', 'SBUX' => 'Starbucks Corporation', 'COST' => 'Costco Wholesale',
            'PEP' => 'PepsiCo Inc.', 'MDLZ' => 'Mondelez International', 'KO' => 'Coca-Cola Company',
            'JPM' => 'JPMorgan Chase & Co.', 'BAC' => 'Bank of America', 'WFC' => 'Wells Fargo & Co.',
            'GS' => 'Goldman Sachs Group', 'MS' => 'Morgan Stanley', 'V' => 'Visa Inc.',
            'MA' => 'Mastercard Inc.', 'BRK-B' => 'Berkshire Hathaway (Class B)', 'JNJ' => 'Johnson & Johnson',
            'UNH' => 'UnitedHealth Group', 'LLY' => 'Eli Lilly and Company', 'ABBV' => 'AbbVie Inc.',
            'MRK' => 'Merck & Co.', 'PFE' => 'Pfizer Inc.', 'TMO' => 'Thermo Fisher Scientific',
            'ABT' => 'Abbott Laboratories', 'DHR' => 'Danaher Corporation', 'AMGN' => 'Amgen Inc.',
            'GILD' => 'Gilead Sciences', 'REGN' => 'Regeneron Pharmaceuticals', 'VRTX' => 'Vertex Pharmaceuticals',
            'ISRG' => 'Intuitive Surgical', 'WMT' => 'Walmart Inc.', 'PG' => 'Procter & Gamble',
            'HD' => 'Home Depot Inc.', 'MCD' => "McDonald's Corporation", 'NKE' => 'Nike Inc.',
            'DIS' => 'Walt Disney Company', 'CMCSA' => 'Comcast Corporation', 'T' => 'AT&T Inc.',
            'VZ' => 'Verizon Communications', 'TMUS' => 'T-Mobile US', 'XOM' => 'Exxon Mobil',
            'CVX' => 'Chevron Corporation', 'BA' => 'Boeing Company', 'CAT' => 'Caterpillar Inc.',
            'GE' => 'GE Aerospace', 'HON' => 'Honeywell International', 'RTX' => 'RTX Corporation',
            'UNP' => 'Union Pacific', 'UPS' => 'United Parcel Service', 'LIN' => 'Linde plc',
            'COP' => 'ConocoPhillips', 'BKNG' => 'Booking Holdings', 'ABNB' => 'Airbnb Inc.',
            'UBER' => 'Uber Technologies', 'DASH' => 'DoorDash Inc.', 'COIN' => 'Coinbase Global',
            'SHOP' => 'Shopify Inc.', 'MSTR' => 'MicroStrategy Inc.', 'MELI' => 'MercadoLibre Inc.',
            'PDD' => 'PDD Holdings', 'F' => 'Ford Motor Company', 'GM' => 'General Motors',
            'C' => 'Citigroup Inc.', 'AXP' => 'American Express', 'BX' => 'Blackstone Inc.',
            'SPGI' => 'S&P Global Inc.', 'NEE' => 'NextEra Energy', 'DE' => 'Deere & Company',
            'LMT' => 'Lockheed Martin', 'MMM' => '3M Company', 'TGT' => 'Target Corporation',
            'LOW' => "Lowe's Companies", 'SCHW' => 'Charles Schwab', 'BLK' => 'BlackRock Inc.',
            'MO' => 'Altria Group', 'PM' => 'Philip Morris International', 'CL' => 'Colgate-Palmolive',
            'FDX' => 'FedEx Corporation', 'ADP' => 'Automatic Data Processing', 'WDAY' => 'Workday Inc.',
            'FTNT' => 'Fortinet Inc.', 'DDOG' => 'Datadog Inc.', 'TEAM' => 'Atlassian Corporation',
            'ZS' => 'Zscaler Inc.', 'SNOW' => 'Snowflake Inc.', 'WDC' => 'Western Digital',
            'MCHP' => 'Microchip Technology', 'ON' => 'ON Semiconductor', 'GEHC' => 'GE HealthCare',
            'CEG' => 'Constellation Energy', 'CSX' => 'CSX Corporation', 'PCAR' => 'PACCAR Inc.',
            'ROP' => 'Roper Technologies', 'ADSK' => 'Autodesk Inc.', 'CTAS' => 'Cintas Corporation',
            'ORLY' => "O'Reilly Automotive", 'AZO' => 'AutoZone Inc.', 'MAR' => 'Marriott International',
            'ODFL' => 'Old Dominion Freight Line', 'EA' => 'Electronic Arts', 'TTD' => 'The Trade Desk',
            'KDP' => 'Keurig Dr Pepper', 'KHC' => 'Kraft Heinz Company', 'MNST' => 'Monster Beverage',
            'EXC' => 'Exelon Corporation', 'XEL' => 'Xcel Energy', 'IDXX' => 'IDEXX Laboratories',
            'BIIB' => 'Biogen Inc.', 'DXCM' => 'DexCom Inc.', 'ROST' => 'Ross Stores',
            'CPRT' => 'Copart Inc.', 'FAST' => 'Fastenal Company', 'PAYX' => 'Paychex Inc.',
            'VRSK' => 'Verisk Analytics', 'CCEP' => 'Coca-Cola Europacific', 'LULU' => 'Lululemon Athletica',
            'BKR' => 'Baker Hughes', 'GEV' => 'GE Vernova', 'WMB' => 'Williams Companies',
            'APP' => 'AppLovin Corporation', 'HOOD' => 'Robinhood Markets',
        ];
    }
}
