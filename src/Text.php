<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * String helpers that match JavaScript's behavior, so page meta and user
 * agents come out the same as in the TypeScript gate: whitespace is
 * JavaScript's \s set, and lengths count UTF-16 code units.
 */
final class Text
{
    /** JavaScript's \s: ASCII whitespace plus the Unicode space separators, line and paragraph separators, and the BOM. */
    private const JS_SPACE = '\x{09}-\x{0D}\x{20}\x{A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    private const VALID = '(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})';

    /** A truncated multi-byte sequence, which a UTF-8 decoder replaces with one U+FFFD. */
    private const PARTIAL = '(?:\xE0[\xA0-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]|\xED[\x80-\x9F]|\xF0[\x90-\xBF][\x80-\xBF]?|[\xF1-\xF3][\x80-\xBF]{1,2}|\xF4[\x80-\x8F][\x80-\xBF]?)';

    /** Decodes bytes as UTF-8 the way a browser's decoder does: invalid sequences become U+FFFD. */
    public static function utf8(string $s): string
    {
        if (preg_match('//u', $s) === 1) {
            return $s;
        }
        $out = preg_replace_callback(
            '/(' . self::VALID . '+)|' . self::PARTIAL . '|./s',
            static fn (array $m): string => isset($m[1]) && $m[1] !== '' ? $m[1] : "\u{FFFD}",
            $s,
        );
        return $out ?? '';
    }

    /** Collapses runs of whitespace to one space and trims, like .replace(/\s+/g, " ").trim(). */
    public static function collapse(string $s): string
    {
        $s = preg_replace('/[' . self::JS_SPACE . ']+/u', ' ', $s) ?? $s;
        return self::trim($s);
    }

    /** JavaScript's String.prototype.trim. */
    public static function trim(string $s): string
    {
        return preg_replace('/^[' . self::JS_SPACE . ']+|[' . self::JS_SPACE . ']+$/u', '', $s) ?? $s;
    }

    /** JavaScript's toLowerCase, when mbstring is present; ASCII lowercase otherwise. */
    public static function lower(string $s): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
    }

    /** The length in UTF-16 code units, as JavaScript's .length counts. */
    public static function length16(string $s): int
    {
        $n = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($s[$i]);
            if ($b < 0x80 || $b >= 0xC0) {
                $n += $b >= 0xF0 ? 2 : 1;
            }
        }
        return $n;
    }

    /**
     * The first $units UTF-16 code units, like .slice(0, units). A character
     * outside the Basic Multilingual Plane that the cut would split is
     * dropped, since UTF-8 can't hold half of it.
     */
    public static function slice16(string $s, int $units): string
    {
        if (strlen($s) <= $units) {
            return $s;
        }
        $n = 0;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($s[$i]);
            if ($b >= 0x80 && $b < 0xC0) {
                continue;
            }
            $w = $b >= 0xF0 ? 2 : 1;
            if ($n + $w > $units) {
                return substr($s, 0, $i);
            }
            $n += $w;
        }
        return $s;
    }

    /** UTF-8 for a code point, or null when it isn't one (above U+10FFFF). Surrogates become U+FFFD. */
    public static function chr(int $cp): ?string
    {
        if ($cp < 0 || $cp > 0x10FFFF) {
            return null;
        }
        if ($cp >= 0xD800 && $cp <= 0xDFFF) {
            return "\u{FFFD}";
        }
        if ($cp < 0x80) {
            return chr($cp);
        }
        if ($cp < 0x800) {
            return chr(0xC0 | ($cp >> 6)) . chr(0x80 | ($cp & 0x3F));
        }
        if ($cp < 0x10000) {
            return chr(0xE0 | ($cp >> 12)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
        }
        return chr(0xF0 | ($cp >> 18)) . chr(0x80 | (($cp >> 12) & 0x3F)) . chr(0x80 | (($cp >> 6) & 0x3F)) . chr(0x80 | ($cp & 0x3F));
    }
}
