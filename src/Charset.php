<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * Decodes a page to UTF-8 in the charset its Content-Type names, as the
 * TypeScript gate's decodePage does with TextDecoder: UTF-8 when it names
 * none, or one this install can't decode. Only the page meta reads the
 * decoded copy; a page passed through keeps its own bytes.
 *
 * Labels follow the WHATWG Encoding Standard, so iso-8859-1, latin1, and
 * us-ascii decode as windows-1252, as browsers do. Other charsets need
 * mbstring or iconv.
 */
final class Charset
{
    /** WHATWG labels for windows-1252. */
    private const WINDOWS_1252 = ['ansi_x3.4-1968', 'ascii', 'cp1252', 'cp819', 'csisolatin1', 'ibm819', 'iso-8859-1', 'iso-ir-100', 'iso8859-1', 'iso88591',
        'iso_8859-1', 'iso_8859-1:1987', 'l1', 'latin1', 'us-ascii', 'windows-1252', 'x-cp1252'];

    private const UTF8 = ['unicode-1-1-utf-8', 'unicode11utf8', 'unicode20utf8', 'utf-8', 'utf8', 'x-unicode20utf8'];

    /** windows-1252 bytes 0x80 to 0x9F, as code points. */
    private const HIGH = [0x20AC, 0x81, 0x201A, 0x0192, 0x201E, 0x2026, 0x2020, 0x2021, 0x02C6, 0x2030, 0x0160, 0x2039, 0x0152, 0x8D, 0x017D, 0x8F,
        0x90, 0x2018, 0x2019, 0x201C, 0x201D, 0x2022, 0x2013, 0x2014, 0x02DC, 0x2122, 0x0161, 0x203A, 0x0153, 0x9D, 0x017E, 0x0178];

    public static function decode(string $bytes, string $contentType): string
    {
        $label = preg_match('/charset\s*=\s*"?([\w.:-]+)/i', $contentType, $m) === 1 ? strtolower($m[1]) : null;
        if ($label === null || in_array($label, self::UTF8, true)) {
            return Text::utf8($bytes);
        }
        if (in_array($label, self::WINDOWS_1252, true)) {
            return self::windows1252($bytes);
        }
        $converted = self::convert($bytes, $label);
        return Text::utf8($converted ?? $bytes);
    }

    private static function windows1252(string $bytes): string
    {
        if (preg_match('/[\x80-\xFF]/', $bytes) !== 1) {
            return $bytes;
        }
        $out = '';
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) {
            $b = ord($bytes[$i]);
            $out .= $b < 0x80 ? $bytes[$i] : (string) Text::chr($b < 0xA0 ? self::HIGH[$b - 0x80] : $b);
        }
        return $out;
    }

    /** mbstring's transfer encodings, which aren't page charsets (charset=html-entities would decode entities). */
    private const NOT_CHARSETS = ['7bit', '8bit', 'auto', 'base64', 'html', 'html-entities', 'pass', 'qprint', 'quoted-printable', 'uuencode', 'wchar'];

    /** Through mbstring or iconv; null when neither knows the charset. */
    private static function convert(string $bytes, string $label): ?string
    {
        if (in_array($label, self::NOT_CHARSETS, true)) {
            return null;
        }
        if (function_exists('mb_convert_encoding')) {
            try {
                $out = mb_convert_encoding($bytes, 'UTF-8', $label);
                if (is_string($out)) {
                    return $out;
                }
            } catch (\ValueError) {
                // mbstring doesn't know this label.
            }
        }
        if (function_exists('iconv')) {
            $out = @iconv($label, 'UTF-8//IGNORE', $bytes);
            if (is_string($out)) {
                return $out;
            }
        }
        return null;
    }
}
