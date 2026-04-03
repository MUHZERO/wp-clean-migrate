<?php

declare(strict_types=1);

namespace MuhCleanMigrator;

/**
 * Lightweight PSR-4-style autoloader for plugin classes.
 */
final class Autoloader
{
    private const PREFIX = 'MuhCleanMigrator\\';

    public static function register(): void
    {
        spl_autoload_register([self::class, 'autoload']);
    }

    /**
     * @param string $class Fully-qualified class name.
     */
    private static function autoload(string $class): void
    {
        if (strpos($class, self::PREFIX) !== 0) {
            return;
        }

        $relative_class = substr($class, strlen(self::PREFIX));
        $relative_path  = str_replace('\\', DIRECTORY_SEPARATOR, $relative_class) . '.php';
        $file           = __DIR__ . DIRECTORY_SEPARATOR . $relative_path;

        if (is_readable($file)) {
            require_once $file;
        }
    }
}
