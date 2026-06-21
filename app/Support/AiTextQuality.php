<?php

declare(strict_types=1);

namespace App\Support;

final class AiTextQuality
{
    /**
     * @var array<int, string>
     */
    private const array DANGLING_ENDINGS = [
        'and',
        'or',
        'but',
        'because',
        'while',
        'with',
        'without',
        'to',
        'of',
        'for',
        'as',
        'at',
        'in',
        'on',
        'by',
        'from',
        'amid',
        'despite',
        'after',
        'before',
        'that',
        'which',
        'who',
        'where',
        'when',
        'if',
        'than',
        'into',
        'including',
        'due to',
        'based on',
        've',
        'veya',
        'ama',
        'çünkü',
        'ile',
        'için',
        'gibi',
    ];

    public static function completeParagraph(?string $text, int $maxCharacters = 700): ?string
    {
        $text = self::normalize($text);

        if ($text === null) {
            return null;
        }

        $text = self::limitAtSentenceBoundary($text, $maxCharacters);

        return self::isIncomplete($text, requireSentenceEnd: true) ? null : $text;
    }

    public static function completeListItem(?string $text, int $maxCharacters = 180): ?string
    {
        $text = self::normalize($text);

        if ($text === null) {
            return null;
        }

        $text = self::limitAtWordBoundary($text, $maxCharacters);

        return self::isIncomplete($text) ? null : $text;
    }

    public static function isIncomplete(?string $text, bool $requireSentenceEnd = false): bool
    {
        $text = self::normalize($text);

        if ($text === null) {
            return true;
        }

        if (preg_match('/(?:…|\.{3})$/u', $text) === 1) {
            return true;
        }

        $terminal = trim($text, " \t\n\r\0\x0B\"'”’)]}»");

        if ($terminal === '') {
            return true;
        }

        if (preg_match('/[,;:–—-]$/u', $terminal) === 1) {
            return true;
        }

        $ending = trim((string) preg_replace('/[.!?]+$/u', '', $terminal));

        foreach (self::DANGLING_ENDINGS as $word) {
            if (preg_match('/(?:^|\s)'.preg_quote($word, '/').'$/iu', $ending) === 1) {
                return true;
            }
        }

        return $requireSentenceEnd && preg_match('/[.!?]$/u', $terminal) !== 1;
    }

    private static function normalize(?string $text): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);

        return $text !== '' ? $text : null;
    }

    private static function limitAtSentenceBoundary(string $text, int $maxCharacters): string
    {
        if ($maxCharacters <= 0 || mb_strlen($text) <= $maxCharacters) {
            return $text;
        }

        $prefix = mb_substr($text, 0, $maxCharacters);

        if (preg_match_all('/[.!?](?=[\s"\')\]}»”’]|$)/u', $prefix, $matches, PREG_OFFSET_CAPTURE) === 0) {
            return trim($prefix);
        }

        $last = end($matches[0]);
        $candidate = trim(substr($prefix, 0, $last[1] + strlen($last[0])));

        return mb_strlen($candidate) >= min(120, (int) floor($maxCharacters * 0.5))
            ? $candidate
            : trim($prefix);
    }

    private static function limitAtWordBoundary(string $text, int $maxCharacters): string
    {
        if ($maxCharacters <= 0 || mb_strlen($text) <= $maxCharacters) {
            return $text;
        }

        $prefix = mb_substr($text, 0, $maxCharacters);
        $lastSpace = mb_strrpos($prefix, ' ');

        return trim($lastSpace !== false ? mb_substr($prefix, 0, $lastSpace) : $prefix);
    }
}
