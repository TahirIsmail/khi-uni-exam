<?php

namespace App\Support\Html;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Question text is rich text written by staff, so it is cleaned before it is stored: only a small
 * list of tags and attributes survives, and links must be http(s). Everything else (scripts, event
 * handlers, styles, iframes) is dropped, and what is stored is what candidates later see.
 *
 * It also produces the plain text used for searching and the hash used to spot duplicates.
 */
final class QuestionHtml
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $clean = trim(self::sanitizer()->sanitize($html));

        return $clean === '' ? null : $clean;
    }

    /** Plain text, for searching, length checks and printed papers. */
    public static function toText(?string $html): string
    {
        if ($html === null) {
            return '';
        }

        $spaced = preg_replace('/<(br|\/p|\/li|\/tr|\/h[1-6])\s*\/?>/i', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * SHA-256 of the question's meaning: lower-cased words of the stem and the options, punctuation
     * dropped and options sorted, so the same question written twice is recognised.
     *
     * @param  list<string>  $optionBodies
     */
    public static function contentHash(?string $stem, array $optionBodies = []): string
    {
        $normalise = static function (?string $value): string {
            $text = mb_strtolower(self::toText($value));

            return trim((string) preg_replace(['/[^\p{L}\p{N}\s]+/u', '/\s+/u'], ['', ' '], $text));
        };

        $options = array_values(array_filter(array_map($normalise, $optionBodies), fn (string $option): bool => $option !== ''));
        sort($options);

        return hash('sha256', $normalise($stem)."\n".implode("\n", $options));
    }

    private static function sanitizer(): HtmlSanitizer
    {
        return self::$sanitizer ??= new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowElement('p')
                ->allowElement('br')
                ->allowElement('strong')
                ->allowElement('b')
                ->allowElement('em')
                ->allowElement('i')
                ->allowElement('u')
                ->allowElement('sub')
                ->allowElement('sup')
                ->allowElement('ul')
                ->allowElement('ol')
                ->allowElement('li')
                ->allowElement('blockquote')
                ->allowElement('code')
                ->allowElement('pre')
                ->allowElement('table')
                ->allowElement('thead')
                ->allowElement('tbody')
                ->allowElement('tr')
                ->allowElement('th', ['colspan', 'rowspan'])
                ->allowElement('td', ['colspan', 'rowspan'])
                ->allowElement('a', ['href', 'title'])
                ->allowElement('img', ['src', 'alt', 'width', 'height'])
                ->allowLinkSchemes(['http', 'https'])
                ->allowMediaSchemes(['http', 'https'])
                // Pictures stored by this app are served from its own paths.
                ->allowRelativeLinks()
                ->allowRelativeMedias()
                ->dropElement('style')
                ->dropElement('script')
                ->dropElement('iframe')
                ->dropAttribute('style', '*')
                ->dropAttribute('class', '*')
                ->dropAttribute('id', '*')
                ->withMaxInputLength(200_000)
        );
    }
}
