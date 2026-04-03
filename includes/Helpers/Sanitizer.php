<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Helpers;

/**
 * Shared text and HTML cleanup helpers.
 */
final class Sanitizer
{
    public function cleanText(string $text): string
    {
        $text = wp_strip_all_tags($text, true);
        $text = preg_replace('/casino|poker|bet|slot|gambling/i', '', $text);
        $text = preg_replace('/\s+/', ' ', (string) $text);

        return trim((string) $text);
    }

    public function cleanHtml(string $html): string
    {
        $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html);
        $html = preg_replace('#<iframe\b[^>]*>.*?</iframe>#is', '', $html);
        $html = preg_replace('#https?://[^\s"\']+#i', '', $html);
        $html = preg_replace('/casino|poker|bet|slot|gambling/i', '', $html);

        $allowed = [
            'p'      => [],
            'br'     => [],
            'strong' => [],
            'b'      => [],
            'em'     => [],
            'i'      => [],
            'ul'     => [],
            'ol'     => [],
            'li'     => [],
        ];

        $html = wp_kses((string) $html, $allowed);
        $html = preg_replace('/\s+/', ' ', (string) $html);

        return trim((string) $html);
    }
}
