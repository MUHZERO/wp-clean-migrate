<?php
/**
 * Plugin Name: Wp Clean Migrate
 * Description: Simple one-file WooCommerce migrator for categories, products, and customers.
 * Version: 1.0.0
 * Author: MuhDroid
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

require_once __DIR__ . '/includes/Autoloader.php';

\MuhCleanMigrator\Autoloader::register();

if (!class_exists('Muh_Clean_Migrator', false)) {
    /**
     * Backwards-compatible bootstrap wrapper for the legacy plugin class.
     */
    final class Muh_Clean_Migrator
    {
        public static function init(): void
        {
            \MuhCleanMigrator\Plugin::init();
        }
    }
}

Muh_Clean_Migrator::init();
