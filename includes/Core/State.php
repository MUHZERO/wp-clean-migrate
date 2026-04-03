<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Core;

/**
 * Manages persisted migration state in WordPress options.
 */
final class State
{
    public const OPTION_KEY = 'muh_clean_migrator_state';

    /**
     * Returns the normalized plugin state.
     *
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $state = get_option(self::OPTION_KEY, []);

        $default_stats = [
            'categories_created' => 0,
            'categories_updated' => 0,
            'products_created'   => 0,
            'products_updated'   => 0,
            'variations_created' => 0,
            'variations_updated' => 0,
            'customers_created'  => 0,
            'customers_updated'  => 0,
            'orders_created'     => 0,
            'orders_updated'     => 0,
            'orders_skipped'     => 0,
            'images_downloaded'  => 0,
            'images_skipped'     => 0,
            'images_failed'      => 0,
            'menus_created'      => 0,
            'menu_items_created' => 0,
            'menu_items_skipped' => 0,
        ];

        $state = wp_parse_args($state, [
            'category_map' => [],
            'product_map'  => [],
            'variation_map'=> [],
            'customer_map' => [],
            'order_map'    => [],
            'stats'        => [],
        ]);

        $state['stats'] = wp_parse_args(
            is_array($state['stats']) ? $state['stats'] : [],
            $default_stats
        );

        return $state;
    }

    /**
     * Persists plugin state without autoloading it.
     *
     * @param array<string, mixed> $state Plugin state.
     */
    public function save(array $state): void
    {
        update_option(self::OPTION_KEY, $state, false);
    }

    /**
     * Removes plugin state from the options table.
     */
    public function reset(): void
    {
        delete_option(self::OPTION_KEY);
    }
}
