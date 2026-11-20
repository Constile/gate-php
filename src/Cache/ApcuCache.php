<?php

declare(strict_types=1);

namespace Constile\Gate\Cache;

/** Shared memory through APCu: the fastest choice when the extension is on. */
final class ApcuCache implements ConfigCache
{
    public static function available(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled();
    }

    public function get(string $key): ?array
    {
        $v = apcu_fetch($key, $ok);
        return $ok && is_array($v) ? $v : null;
    }

    public function set(string $key, array $entry, int $ttl): void
    {
        apcu_store($key, $entry, max(1, $ttl));
    }

    public function delete(string $key): void
    {
        apcu_delete($key);
    }
}
