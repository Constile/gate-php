<?php

declare(strict_types=1);

namespace Constile\Gate;

/**
 * Locks shared by every PHP process on the server, through flock() on small
 * files in a directory. The operating system releases a lock when its
 * process ends, so a crashed request never leaves one held. When the
 * directory can't be used, tryLock() grants a lock that guards nothing and
 * isHeld() answers false: the gate then works as if it had no locks.
 */
final class Locks implements LockStore
{
    private bool $usable;

    public function __construct(private string $dir)
    {
        $this->usable = is_dir($dir) || @mkdir($dir, 0700, true) || is_dir($dir);
    }

    /** The lock, or null when another process (or another lock in this one) holds it. */
    public function tryLock(string $name, bool $shared = false): ?Lock
    {
        $fp = $this->open($name);
        if ($fp === null) {
            return new Lock();
        }
        if (!flock($fp, ($shared ? LOCK_SH : LOCK_EX) | LOCK_NB)) {
            fclose($fp);
            return null;
        }
        // The operating system also releases it when the process ends.
        return new Lock(static function () use ($fp): void {
            flock($fp, LOCK_UN);
            fclose($fp);
        });
    }

    /** Waits up to $seconds for the lock; null when it's still held then. */
    public function lock(string $name, float $seconds): ?Lock
    {
        $deadline = microtime(true) + $seconds;
        do {
            $lock = $this->tryLock($name);
            if ($lock !== null) {
                return $lock;
            }
            usleep(25000);
        } while (microtime(true) < $deadline);
        return null;
    }

    /** Whether some process holds the lock exclusively right now. */
    public function isHeld(string $name): bool
    {
        $fp = $this->open($name);
        if ($fp === null) {
            return false;
        }
        $free = flock($fp, LOCK_SH | LOCK_NB);
        if ($free) {
            flock($fp, LOCK_UN);
        }
        fclose($fp);
        return !$free;
    }

    /** @return resource|null */
    private function open(string $name)
    {
        if (!$this->usable) {
            return null;
        }
        $fp = @fopen($this->dir . DIRECTORY_SEPARATOR . preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) . '.lock', 'c');
        return $fp === false ? null : $fp;
    }
}
