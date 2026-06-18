<?php

declare(strict_types=1);

namespace App\Services\News;

/**
 * Produces a stable fingerprint for a headline so the same story reported by
 * different outlets collapses to one value. Lowercases, strips HTML/markup and
 * punctuation, drops common EN/TR stopwords, sorts the remaining significant
 * tokens, and hashes the result.
 */
class TitleNormalizer
{
    /** @var array<int, string> */
    private const STOPWORDS = [
        // English
        'the', 'a', 'an', 'and', 'or', 'of', 'to', 'in', 'on', 'for', 'with',
        'at', 'by', 'from', 'as', 'is', 'are', 'was', 'were', 'be', 'has',
        'have', 'had', 'it', 'its', 'this', 'that', 'after', 'over', 'amid',
        'says', 'say', 'said', 'new', 'up', 'down',
        // Turkish
        've', 'ile', 'için', 'bir', 'bu', 'da', 'de', 'mi', 'mu', 'ya',
        'ama', 'çok', 'daha', 'olarak', 'oldu', 'sonra',
    ];

    public function fingerprint(string $title, ?string $market = null): string
    {
        $tokens = $this->tokens($title);

        return hash('sha256', ($market ?? '').'|'.implode(' ', $tokens));
    }

    /**
     * @return array<int, string>
     */
    public function tokens(string $title): array
    {
        $text = strip_tags($title);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = mb_strtolower($text, 'UTF-8');

        // Split on any non-letter/non-digit (unicode aware).
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $tokens = array_values(array_filter(
            $parts,
            fn (string $token): bool => mb_strlen($token) > 1 && ! in_array($token, self::STOPWORDS, true),
        ));

        sort($tokens);

        return $tokens;
    }
}
