<?php

declare(strict_types=1);

namespace Constile\Gate;

/** A lock from a LockStore. Release it when done; it's released anyway when it's destroyed. */
final class Lock
{
    /** @var (\Closure(): void)|null */
    private ?\Closure $release;

    /** @param (\Closure(): void)|null $release Releases the lock; null for a lock that guards nothing. */
    public function __construct(?\Closure $release = null)
    {
        $this->release = $release;
    }

    public function release(): void
    {
        $release = $this->release;
        $this->release = null;
        if ($release !== null) {
            $release();
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
