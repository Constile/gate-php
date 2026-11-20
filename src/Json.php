<?php

declare(strict_types=1);

namespace Constile\Gate;

/** JSON as JavaScript's JSON.stringify writes it: no escaped slashes or Unicode. */
final class Json
{
    public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE;

    public static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS | JSON_THROW_ON_ERROR);
    }

    /** Decodes objects as stdClass, so {} and [] stay distinct when re-encoded. */
    public static function decode(string $text): mixed
    {
        return json_decode($text, false, 512, JSON_THROW_ON_ERROR);
    }
}
