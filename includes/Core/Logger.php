<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Core;

/**
 * Thin wrapper around WP-CLI logging functions.
 */
final class Logger
{
    public function log(string $message): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::log($message);
        }
    }

    public function warning(string $message): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::warning($message);
        }
    }

    public function error(string $message): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::error($message, false);
        }
    }
}
