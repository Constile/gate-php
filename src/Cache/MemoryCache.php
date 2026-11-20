<?php

declare(strict_types=1);

namespace Constile\Gate\Cache;

/** Keeps entries for the life of the PHP process. For tests and long-running servers. */
final class MemoryCache implements ConfigCache
{
    /** @var array<string, array<string, mixed>> */
    private array $entries = [];

    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }

    public function set(string $key, array $entry, int $ttl): void
    {
        $this->entries[$key] = $entry;
    }

    public function delete(string $key): void
    {
        unset($this->entries[$key]);
    }
}
