<?php

declare(strict_types=1);

namespace Constile\Gate\Cache;

/** Adapts a host's cache through three closures (get, set, delete). */
final class CallbackCache implements ConfigCache
{
    /**
     * @param \Closure(string): mixed $get
     * @param \Closure(string, array<string, mixed>, int): void $set
     * @param \Closure(string): void $delete
     */
    public function __construct(private \Closure $get, private \Closure $set, private \Closure $delete)
    {
    }

    public function get(string $key): ?array
    {
        $v = ($this->get)($key);
        return is_array($v) ? $v : null;
    }

    public function set(string $key, array $entry, int $ttl): void
    {
        ($this->set)($key, $entry, $ttl);
    }

    public function delete(string $key): void
    {
        ($this->delete)($key);
    }
}
