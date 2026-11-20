<?php

declare(strict_types=1);

namespace Constile\Gate;

/** A lock from Locks. Release it when done; it's released anyway when it's destroyed or the process ends. */
final class Lock
{
    /** @param resource|null $fp */
    public function __construct(private $fp)
    {
    }

    public function release(): void
    {
        if (is_resource($this->fp)) {
            flock($this->fp, LOCK_UN);
            fclose($this->fp);
        }
        $this->fp = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
