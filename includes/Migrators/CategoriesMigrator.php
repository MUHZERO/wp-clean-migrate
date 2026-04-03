<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Migrators;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;

/**
 * Migrates product categories from the old store.
 */
final class CategoriesMigrator
{
    private WooClient $client;

    private State $state;

    private Logger $logger;

    private Sanitizer $sanitizer;

    public function __construct(WooClient $client, State $state, Logger $logger, Sanitizer $sanitizer)
    {
        $this->client    = $client;
        $this->state     = $state;
        $this->logger    = $logger;
        $this->sanitizer = $sanitizer;
    }

    /**
     * Syncs category batches from the old store.
     *
     * @param array<string, mixed> $assoc_args CLI args.
     */
    public function sync(array $assoc_args): void
    {
        $state    = $this->state->get();
        $page     = max(1, (int) ($assoc_args['page'] ?? 1));
        $per_page = min(100, max(1, (int) ($assoc_args['per-page'] ?? 20)));
        $dry_run  = !empty($assoc_args['dry-run']);

        $this->logger->log("Syncing categories | page={$page} per_page={$per_page} dry_run=" . ($dry_run ? 'yes' : 'no'));

        $items = $this->client->get('products/categories', [
            'page'     => $page,
            'per_page' => $per_page,
            'orderby'  => 'id',
            'order'    => 'asc',
        ]);

        if (empty($items)) {
            $this->logger->log('No categories returned.');
            return;
        }

        usort(
            $items,
            static function (array $a, array $b): int {
                return ((int) ($a['parent'] ?? 0)) <=> ((int) ($b['parent'] ?? 0));
            }
        );

        foreach ($items as $item) {
            $old_id = (int) $item['id'];
            $slug   = sanitize_title((string) (!empty($item['slug']) ? $item['slug'] : ($item['name'] ?? '')));
            $name   = $this->sanitizer->cleanText((string) ($item['name'] ?? ''));
            $desc   = $this->sanitizer->cleanHtml((string) ($item['description'] ?? ''));
            $parent = (int) ($item['parent'] ?? 0);

            $payload = [
                'name'        => $name,
                'slug'        => $slug,
                'description' => $desc,
                'parent'      => 0,
            ];

            if ($parent && !empty($state['category_map'][$parent])) {
                $payload['parent'] = (int) $state['category_map'][$parent];
            }

            $existing    = get_term_by('slug', $slug, 'product_cat');
            $existing_id = $existing ? (int) $existing->term_id : 0;

            if ($dry_run) {
                $this->logger->log("[DRY] Category {$name} | old={$old_id} | existing={$existing_id}");
                continue;
            }

            if ($existing_id) {
                $result = wp_update_term($existing_id, 'product_cat', $payload);
                if (is_wp_error($result)) {
                    $this->logger->warning("Failed updating category {$name}: " . $result->get_error_message());
                    continue;
                }

                $state['category_map'][$old_id] = $existing_id;
                ++$state['stats']['categories_updated'];
                $this->logger->log("Updated category: {$name} (old={$old_id}, new={$existing_id})");
            } else {
                $result = wp_insert_term($name, 'product_cat', $payload);
                if (is_wp_error($result)) {
                    $this->logger->warning("Failed creating category {$name}: " . $result->get_error_message());
                    continue;
                }

                $new_id                         = (int) $result['term_id'];
                $state['category_map'][$old_id] = $new_id;
                ++$state['stats']['categories_created'];
                $this->logger->log("Created category: {$name} (old={$old_id}, new={$new_id})");
            }

            $this->state->save($state);
        }

        $this->logger->log('Categories sync completed for this batch.');
    }
}
