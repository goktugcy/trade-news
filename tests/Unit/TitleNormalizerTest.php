<?php

declare(strict_types=1);

use App\Services\News\TitleNormalizer;

it('produces the same fingerprint regardless of case, punctuation and word order', function () {
    $n = new TitleNormalizer;

    $a = $n->fingerprint('Apple beats Q3 earnings estimates!', 'NASDAQ');
    $b = $n->fingerprint('earnings estimates beats apple q3', 'NASDAQ');

    expect($a)->toBe($b);
});

it('separates fingerprints by market', function () {
    $n = new TitleNormalizer;

    expect($n->fingerprint('Aselsan wins defense contract', 'BIST'))
        ->not->toBe($n->fingerprint('Aselsan wins defense contract', 'NASDAQ'));
});

it('drops stopwords so they do not affect the fingerprint', function () {
    $n = new TitleNormalizer;

    expect($n->fingerprint('The Fed is raising the rates', 'NASDAQ'))
        ->toBe($n->fingerprint('Fed raising rates', 'NASDAQ'));
});
