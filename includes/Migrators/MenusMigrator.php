<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Migrators;

use MuhCleanMigrator\API\WordPressClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;

/**
 * Migrates WordPress nav menus from the old site.
 */
final class MenusMigrator
{
    private WordPressClient $client;

    private State $state;

    private Logger $logger;

    private Sanitizer $sanitizer;

    public function __construct(WordPressClient $client, State $state, Logger $logger, Sanitizer $sanitizer)
    {
        $this->client    = $client;
        $this->state     = $state;
        $this->logger    = $logger;
        $this->sanitizer = $sanitizer;
    }

    /**
     * Syncs navigation menus from the old site.
     *
     * @param array<string, mixed> $assoc_args CLI args.
     */
    public function sync(array $assoc_args): void
    {
        $state   = $this->state->get();
        $dry_run = !empty($assoc_args['dry-run']);

        $this->logger->log('Syncing menus | dry_run=' . ($dry_run ? 'yes' : 'no'));

        $menus = $this->client->get('menus', []);

        if (empty($menus)) {
            $this->logger->log('No menus returned.');
            return;
        }

        foreach ($menus as $menu) {
            $old_menu_id = (int) ($menu['id'] ?? 0);
            $menu_name   = $this->sanitizer->cleanText((string) ($menu['name'] ?? ''));

            if ($menu_name === '') {
                continue;
            }

            $existing_menu = wp_get_nav_menu_object($menu_name);
            $new_menu_id   = $existing_menu ? (int) $existing_menu->term_id : 0;

            if ($dry_run) {
                $this->logger->log("[DRY] Menu {$menu_name} old={$old_menu_id} existing=" . ($new_menu_id ?: 0));
            } else {
                if (!$new_menu_id) {
                    $created_menu_id = wp_create_nav_menu($menu_name);

                    if (is_wp_error($created_menu_id)) {
                        $this->logger->warning("Failed creating menu {$menu_name}: " . $created_menu_id->get_error_message());
                        continue;
                    }

                    $new_menu_id = (int) $created_menu_id;
                    ++$state['stats']['menus_created'];
                    $this->state->save($state);

                    $this->logger->log("Created menu {$menu_name} -> {$new_menu_id}");
                } else {
                    $this->logger->log("Using existing menu {$menu_name} -> {$new_menu_id}");
                }
            }

            $menu_items = $this->client->get("menus/{$old_menu_id}/items", [
                'orderby' => 'menu_order',
                'order'   => 'asc',
            ]);

            if (empty($menu_items)) {
                $this->logger->log("No items for menu {$menu_name}");
                continue;
            }

            $item_map = [];

            foreach ($menu_items as $item) {
                $old_item_id = (int) ($item['id'] ?? 0);
                $prepared    = $this->prepareMenuItemPayload($item, $state);

                if (empty($prepared['valid'])) {
                    ++$state['stats']['menu_items_skipped'];
                    $this->state->save($state);
                    $label = $item['title']['rendered'] ?? '';
                    $this->logger->warning('Skipped menu item old=' . $old_item_id . ' label=' . $label);
                    continue;
                }

                if ($dry_run) {
                    $this->logger->log("[DRY] Menu item old={$old_item_id} type={$prepared['type']} title={$prepared['title']}");
                    continue;
                }

                $parent_old_id = (int) ($item['parent'] ?? 0);
                $parent_new_id = 0;

                if ($parent_old_id && !empty($item_map[$parent_old_id])) {
                    $parent_new_id = (int) $item_map[$parent_old_id];
                }

                $args = [
                    'menu-item-title'     => $prepared['title'],
                    'menu-item-status'    => 'publish',
                    'menu-item-parent-id' => $parent_new_id,
                    'menu-item-position'  => (int) ($item['menu_order'] ?? 0),
                    'menu-item-classes'   => '',
                    'menu-item-target'    => '',
                    'menu-item-xfn'       => '',
                ];

                if ($prepared['type'] === 'taxonomy') {
                    $args['menu-item-type']      = 'taxonomy';
                    $args['menu-item-object']    = 'product_cat';
                    $args['menu-item-object-id'] = $prepared['object_id'];
                } elseif ($prepared['type'] === 'post_type') {
                    $args['menu-item-type']      = 'post_type';
                    $args['menu-item-object']    = 'page';
                    $args['menu-item-object-id'] = $prepared['object_id'];
                } elseif ($prepared['type'] === 'custom') {
                    $args['menu-item-type'] = 'custom';
                    $args['menu-item-url']  = $prepared['url'];
                } else {
                    ++$state['stats']['menu_items_skipped'];
                    $this->state->save($state);
                    $this->logger->warning("Skipped unsupported menu item old={$old_item_id}");
                    continue;
                }

                $new_item_id = wp_update_nav_menu_item($new_menu_id, 0, $args);

                if (is_wp_error($new_item_id)) {
                    ++$state['stats']['menu_items_skipped'];
                    $this->state->save($state);
                    $this->logger->warning("Failed creating menu item old={$old_item_id}: " . $new_item_id->get_error_message());
                    continue;
                }

                $item_map[$old_item_id] = (int) $new_item_id;
                ++$state['stats']['menu_items_created'];
                $this->state->save($state);

                $this->logger->log("Created menu item old={$old_item_id} -> new={$new_item_id}");
            }
        }

        $this->logger->log('Menus sync completed.');
    }

    /**
     * @param array<string, mixed> $item  Menu item payload.
     * @param array<string, mixed> $state Saved migration state.
     * @return array{valid:bool,type:string,title:string,object_id:int,url:string}
     */
    private function prepareMenuItemPayload(array $item, array $state): array
    {
        $title = $this->sanitizer->cleanText((string) ($item['title']['rendered'] ?? $item['title'] ?? ''));
        $type  = sanitize_key((string) ($item['type'] ?? ''));
        $obj   = sanitize_key((string) ($item['object'] ?? ''));
        $url   = trim((string) ($item['url'] ?? ''));

        $result = [
            'valid'     => false,
            'type'      => '',
            'title'     => $title,
            'object_id' => 0,
            'url'       => '',
        ];

        if ($type === 'taxonomy' && $obj === 'product_cat') {
            $old_object_id = (int) ($item['object_id'] ?? 0);

            if (!$old_object_id || empty($state['category_map'][$old_object_id])) {
                return $result;
            }

            $result['valid']     = true;
            $result['type']      = 'taxonomy';
            $result['object_id'] = (int) $state['category_map'][$old_object_id];
            return $result;
        }

        if ($type === 'post_type' && $obj === 'page') {
            $slug = '';

            if (!empty($item['object_slug'])) {
                $slug = sanitize_title((string) $item['object_slug']);
            }

            if ($slug === '' && $url !== '') {
                $slug = sanitize_title((string) basename((string) parse_url($url, PHP_URL_PATH)));
            }

            if ($slug === '') {
                return $result;
            }

            $page = get_page_by_path($slug, OBJECT, 'page');
            if (!$page) {
                return $result;
            }

            $result['valid']     = true;
            $result['type']      = 'post_type';
            $result['object_id'] = (int) $page->ID;
            return $result;
        }

        if ($type === 'custom' && $this->isSafeMenuUrl($url)) {
            $result['valid'] = true;
            $result['type']  = 'custom';
            $result['url']   = esc_url_raw($url);
        }

        return $result;
    }

    private function isSafeMenuUrl(string $url): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        if (stripos($url, 'javascript:') === 0 || stripos($url, 'data:') === 0) {
            return false;
        }

        return (bool) esc_url_raw($url);
    }
}
