<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * Who is asking for a page. A port of classifyClient and signatureAgentOrigin
 * in packages/core/src/agents.ts. Registry entries are the config's agents.
 */
final class Classifier
{
    private const GENERIC_BOT = '/bot\b|crawler|spider|headless|scraper/';

    /**
     * The cookie the offer page's fallback sets in a person's browser (any value). A request that carries it is never
     * taken for a script posing as a browser.
     */
    public const BROWSER_COOKIE = 'constile_browser';

    /**
     * Kind "disguised" (a browser's user agent without the headers browsers send) comes back only when $disguised asks
     * for the check; a gate asks only when its site sends those an ad.
     *
     * @param list<object> $registry
     * @return array{kind: string, agent?: object}
     */
    public static function classify(Headers $headers, array $registry, bool $disguised = false): array
    {
        $ua = Text::lower((string) $headers->get('user-agent'));
        $byLength = array_values(array_filter($registry, 'is_object'));
        // Longest token first, so "claude-searchbot" wins over a shorter token it contains. usort is stable, as JavaScript's sort is.
        usort($byLength, static fn (object $a, object $b): int => Text::length16(self::str($b, 'token')) <=> Text::length16(self::str($a, 'token')));
        foreach ($byLength as $a) {
            $token = self::str($a, 'token');
            if ($token !== '' && str_contains($ua, Text::lower($token))) {
                $kind = self::str($a, 'kind');
                return $kind === 'user_agent' ? ['kind' => 'agent', 'agent' => $a] : ['kind' => $kind, 'agent' => $a];
            }
        }
        // A signed request names its agent in Signature-Agent. The network checks the signature.
        $origin = $headers->has('signature') ? self::signatureAgentOrigin($headers->get('signature-agent')) : null;
        if ($origin !== null) {
            foreach ($registry as $a) {
                if (is_object($a) && self::str($a, 'kind') === 'user_agent' && self::str($a, 'signature_agent') !== ''
                    && self::signatureAgentOrigin('"' . self::str($a, 'signature_agent') . '"') === $origin) {
                    return ['kind' => 'agent', 'agent' => $a];
                }
            }
        }
        if ($ua === '') {
            return ['kind' => 'unknown_client'];
        }
        // Never show an offer to a person: any browser-looking client without a bot marker is a browser.
        if (str_starts_with($ua, 'mozilla/') && preg_match(self::GENERIC_BOT, $ua) !== 1) {
            return ['kind' => $disguised && self::posesAsBrowser($headers) ? 'disguised' : 'browser'];
        }
        return ['kind' => 'unknown_client'];
    }

    /**
     * Whether a request with a browser's user agent leaves out what every browser sends: neither Accept-Language nor
     * Sec-Fetch-Mode (a header counts when it's there at all, even empty), and no browser cookie.
     */
    public static function posesAsBrowser(Headers $headers): bool
    {
        if ($headers->has('accept-language') || $headers->has('sec-fetch-mode')) {
            return false;
        }
        foreach (explode(';', $headers->get('cookie') ?? '') as $part) {
            if (Text::trim(explode('=', $part, 2)[0]) === self::BROWSER_COOKIE) {
                return false;
            }
        }
        return true;
    }

    /** The origin named in a Signature-Agent header, in either the dictionary or the quoted-string form. */
    public static function signatureAgentOrigin(?string $header): ?string
    {
        if (preg_match('/"(https:\/\/[^"]+)"/', $header ?? '', $m) !== 1) {
            return null;
        }
        return Url::parse($m[1])?->origin();
    }

    private static function str(object $o, string $key): string
    {
        $v = $o->{$key} ?? null;
        return is_string($v) ? $v : '';
    }
}
