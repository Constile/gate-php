<?php
/**
 * Loads the gate's classes without Composer, for the WordPress plugin, the
 * Drupal module zip, and the tests. With Composer, use vendor/autoload.php.
 */
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'Constile\\Gate\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});
