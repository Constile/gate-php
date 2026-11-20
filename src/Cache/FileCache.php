<?php

declare(strict_types=1);

namespace Constile\Gate\Cache;

/**
 * One JSON file per key in a directory, written atomically. The default
 * directory is a private folder in the system's temporary directory.
 */
final class FileCache implements ConfigCache
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = rtrim($dir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'constile-gate', '/\\');
    }

    public function get(string $key): ?array
    {
        $text = @file_get_contents($this->path($key));
        if ($text === false) {
            return null;
        }
        $v = json_decode($text, true);
        return is_array($v) ? $v : null;
    }

    public function set(string $key, array $entry, int $ttl): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            return;
        }
        $tmp = @tempnam($this->dir, 'tmp');
        if ($tmp === false) {
            return;
        }
        if (@file_put_contents($tmp, json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) === false || !@rename($tmp, $this->path($key))) {
            @unlink($tmp);
        }
    }

    public function delete(string $key): void
    {
        @unlink($this->path($key));
    }

    private function path(string $key): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . hash('sha256', $key) . '.json';
    }
}
