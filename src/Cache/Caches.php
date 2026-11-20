<?php

declare(strict_types=1);

namespace Constile\Gate\Cache;

final class Caches
{
    /** APCu when it's on, else files in the temporary directory. */
    public static function auto(?string $dir = null): ConfigCache
    {
        return ApcuCache::available() ? new ApcuCache() : new FileCache($dir);
    }
}
