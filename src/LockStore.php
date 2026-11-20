<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * Locks shared by every PHP process that serves the site: the self-fetch
 * slots, the echo markers, and the config fetch. Locks (flock() on files) is
 * the default; a host can pass its own, such as one in its database.
 */
interface LockStore
{
    /** The lock, or null when another process (or another lock in this one) holds it. */
    public function tryLock(string $name): ?Lock;

    /** Waits up to $seconds for the lock; null when it's still held then. */
    public function lock(string $name, float $seconds): ?Lock;

    /** Whether some process holds the lock right now. */
    public function isHeld(string $name): bool;
}
