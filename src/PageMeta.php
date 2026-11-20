<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * Reads what the network needs to match an ad to an article: title,
 * description, keywords, and section. A port of extractPageMeta in
 * packages/core/src/page-meta.ts.
 */
final class PageMeta
{
    /** The only names that decode. */
    private const ENTITIES = ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => ' '];

    /** @return array{title: string, description: string, keywords: list<string>, section: string} */
    public static function extract(string $html, string $fallbackTitle = ''): array
    {
        $html = Text::utf8($html);
        $headEnd = preg_match('/<\/head>/i', $html, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : -1;
        $head = $headEnd > 0 ? substr($html, 0, $headEnd) : Text::slice16($html, 65536);
        $og = self::metaContent($head, 'property', 'og:title');
        $titleTag = preg_match('/<title\b[^>]*>([\s\S]*?)<\/title>/i', $head, $t) === 1 ? $t[1] : '';
        $title = self::decode($og !== '' ? $og : $titleTag);
        if ($title === '') {
            $title = $fallbackTitle;
        }
        $description = self::metaContent($head, 'name', 'description');
        if ($description === '') {
            $description = self::metaContent($head, 'property', 'og:description');
        }
        $keywords = [];
        foreach (explode(',', self::metaContent($head, 'name', 'keywords')) as $k) {
            $k = Text::lower(Text::trim($k));
            if ($k !== '') {
                $keywords[] = $k;
            }
        }
        $section = self::metaContent($head, 'property', 'article:section');
        return [
            'title' => Text::slice16($title, 300),
            'description' => Text::slice16($description, 1000),
            'keywords' => array_slice($keywords, 0, 30),
            'section' => Text::slice16($section, 100),
        ];
    }

    private static function metaContent(string $head, string $attr, string $key): string
    {
        if (preg_match_all('/<meta\b[^>]*>/i', $head, $tags) === false) {
            return '';
        }
        $a = '/\b' . $attr . '\s*=\s*["\']' . preg_quote($key, '/') . '["\']/i';
        foreach ($tags[0] as $tag) {
            if (preg_match($a, $tag) !== 1) {
                continue;
            }
            if (preg_match('/\bcontent\s*=\s*"([^"]*)"|\bcontent\s*=\s*\'([^\']*)\'/i', $tag, $c, PREG_UNMATCHED_AS_NULL) === 1) {
                return self::decode($c[1] ?? $c[2] ?? '');
            }
        }
        return '';
    }

    private static function decode(string $s): string
    {
        $s = preg_replace_callback('/&(#x[0-9a-f]+|#\d+|[a-z0-9]+);/i', static function (array $m): string {
            $k = strtolower($m[1]);
            if (str_starts_with($k, '#')) {
                // A numeric reference decodes only to a real character: not 0, a surrogate, or past U+10FFFF.
                $digits = ltrim(str_starts_with($k, '#x') ? substr($k, 2) : substr($k, 1), '0');
                if ($digits === '' || strlen($digits) > 7) {
                    return $m[0];
                }
                $cp = str_starts_with($k, '#x') ? (int) hexdec($digits) : (int) $digits;
                return $cp > 0 && $cp <= 0x10FFFF && ($cp < 0xD800 || $cp > 0xDFFF) ? (string) Text::chr($cp) : $m[0];
            }
            return self::ENTITIES[$k] ?? $m[0];
        }, $s) ?? $s;
        return Text::collapse($s);
    }
}
