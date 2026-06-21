<?php

declare(strict_types=1);

use App\Support\AiTextQuality;

it('accepts complete financial paragraphs', function () {
    $text = 'Apple shares rose after stronger revenue and margin guidance. Investors still face valuation risk if earnings growth slows.';

    expect(AiTextQuality::completeParagraph($text))->toBe($text);
});

it('rejects paragraphs that end with ellipses or dangling connectors', function (?string $text) {
    expect(AiTextQuality::completeParagraph($text))->toBeNull();
})->with([
    'ellipsis' => ['Revenue improved as demand recovered...'],
    'dangling connector' => ['Revenue improved as demand recovered and'],
    'missing final punctuation' => ['Revenue improved as demand recovered'],
]);
