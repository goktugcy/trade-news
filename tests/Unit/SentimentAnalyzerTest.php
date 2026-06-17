<?php

declare(strict_types=1);

use App\Enums\Sentiment;
use App\Services\News\SentimentAnalyzer;

it('scores clearly positive headlines as positive', function () {
    $result = (new SentimentAnalyzer)->analyze('Company beats estimates with record profit and strong growth');

    expect($result['sentiment'])->toBe(Sentiment::Positive)
        ->and($result['score'])->toBeGreaterThan(0);
});

it('scores clearly negative headlines as negative', function () {
    $result = (new SentimentAnalyzer)->analyze('Shares plunge after profit miss and downgrade amid lawsuit');

    expect($result['sentiment'])->toBe(Sentiment::Negative)
        ->and($result['score'])->toBeLessThan(0);
});

it('treats neutral text as neutral', function () {
    $result = (new SentimentAnalyzer)->analyze('The company held its annual general meeting today');

    expect($result['sentiment'])->toBe(Sentiment::Neutral);
});

it('raises importance for high-impact keywords', function () {
    $plain = (new SentimentAnalyzer)->analyze('A quiet trading day');
    $impactful = (new SentimentAnalyzer)->analyze('SEC investigation and bankruptcy filing announced at earnings');

    expect($impactful['importance'])->toBeGreaterThan($plain['importance']);
});
