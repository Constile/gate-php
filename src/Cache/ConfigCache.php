<?php

declare(strict_types=1);

namespace Constile\Gate\Cache;

/**
 * Where the gate keeps the site's config between requests. Each PHP request
 * starts fresh, so the cache must outlive the request: APCu, a file, or the
 * host's own cache (WordPress transients, Laravel's cache, Drupal's cache).
 * Entries are small arrays of scalars; the gate checks their expiry itself.
 */
interface ConfigCache
{
    /** @return array<string, mixed>|null */
    public function get(string $key): ?array;

    /** @param array<string, mixed> $entry */
    public function set(string $key, array $entry, int $ttl): void;

    public function delete(string $key): void;
}
