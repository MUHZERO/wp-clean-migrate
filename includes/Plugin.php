<?php

declare(strict_types=1);

namespace MuhCleanMigrator;

use MuhCleanMigrator\CLI\Command;
use MuhCleanMigrator\Core\Config;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;

/**
 * Main plugin bootstrap.
 */
final class Plugin
{
    private static bool $initialized = false;

    /**
     * Boots the plugin and registers WP-CLI integration when available.
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        self::$initialized = true;

        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }

        self::disableWooCommerceEmails();

        \WP_CLI::add_command(
            'muh-migrate',
            new Command(
                new Config(new Logger()),
                new State(),
                new Logger(),
                new Sanitizer()
            )
        );
    }

    /**
     * Prevents WooCommerce transactional emails during CLI migrations.
     */
    private static function disableWooCommerceEmails(): void
    {
        add_filter('woocommerce_email_enabled_new_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_processing_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_completed_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_invoice', '__return_false');
        add_filter('woocommerce_email_enabled_failed_order', '__return_false');
        add_filter('woocommerce_email_enabled_cancelled_order', '__return_false');
        add_filter('woocommerce_email_enabled_customer_note', '__return_false');
    }
}
