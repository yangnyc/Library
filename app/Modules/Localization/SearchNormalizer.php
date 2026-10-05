<?php

namespace App\Modules\Localization;

use Normalizer;

/**
 * Produces the value used for searching only. Displayed text is never passed
 * through this: Hebrew vowel points and cantillation marks stay in the source.
 */
class SearchNormalizer
{
    public static function normalize(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        if (class_exists(Normalizer::class)) {
            $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        }

        // Hebrew cantillation marks and points (U+0591–U+05BD, U+05BF, U+05C1–U+05C2,
        // U+05C4–U+05C5, U+05C7). Maqaf (U+05BE) joins words, so it becomes a space.
        $text = preg_replace('/[\x{0591}-\x{05BD}\x{05BF}\x{05C1}\x{05C2}\x{05C4}\x{05C5}\x{05C7}]/u', '', $text);
        $text = preg_replace('/[\x{05BE}\x{05C0}\x{05C3}\x{05F3}\x{05F4}]/u', ' ', $text);

        $text = mb_strtolower($text, 'UTF-8');
        $text = str_replace('ё', 'е', $text);

        // Latin diacritics (é → e) without touching Cyrillic й or Hebrew letters.
        if (class_exists(Normalizer::class)) {
            $decomposed = Normalizer::normalize($text, Normalizer::FORM_D) ?: $text;
            $decomposed = preg_replace('/(?<=\p{Latin})\p{Mn}+/u', '', $decomposed);
            $text = Normalizer::normalize($decomposed, Normalizer::FORM_C) ?: $decomposed;
        }

        $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /** @return list<string> */
    public static function tokens(?string $text, int $max = 8): array
    {
        $normalized = self::normalize($text);

        return $normalized === '' ? [] : array_slice(array_values(array_unique(explode(' ', $normalized))), 0, $max);
    }
}
