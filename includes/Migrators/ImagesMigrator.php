<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Migrators;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\ProductImageImporter;
use MuhCleanMigrator\Helpers\Sanitizer;

/**
 * Imports product and variation images after products have been migrated.
 */
final class ImagesMigrator
{
    private const PRODUCT_META_KEY = '_muh_old_product_id';
    private const VARIATION_META_KEY = '_muh_old_variation_id';

    private WooClient $client;

    private State $state;

    private Logger $logger;

    private Sanitizer $sanitizer;

    private ProductImageImporter $image_importer;

    public function __construct(
        WooClient $client,
        State $state,
        Logger $logger,
        Sanitizer $sanitizer,
        ProductImageImporter $image_importer
    ) {
        $this->client         = $client;
        $this->state          = $state;
        $this->logger         = $logger;
        $this->sanitizer      = $sanitizer;
        $this->image_importer = $image_importer;
    }

    /**
     * Syncs product and variation images from the old store.
     *
     * @param array<string, mixed> $assoc_args CLI args.
     */
    public function sync(array $assoc_args): void
    {
        $state    = $this->state->get();
        $page     = max(1, (int) ($assoc_args['page'] ?? 1));
        $per_page = min(50, max(1, (int) ($assoc_args['per-page'] ?? 5)));
        $dry_run  = !empty($assoc_args['dry-run']);

        $this->logger->log("Syncing product images | page={$page} per_page={$per_page} dry_run=" . ($dry_run ? 'yes' : 'no'));

        $items = $this->client->get('products', [
            'page'     => $page,
            'per_page' => $per_page,
            'orderby'  => 'id',
            'order'    => 'asc',
            'status'   => 'any',
        ]);

        if (empty($items)) {
            $this->logger->log('No products returned for images.');
            return;
        }

        foreach ($items as $item) {
            $old_product_id = (int) ($item['id'] ?? 0);
            $product_name   = $this->sanitizer->cleanText((string) ($item['name'] ?? ''));
            $product_type   = in_array((string) ($item['type'] ?? 'simple'), ['simple', 'variable'], true)
                ? (string) $item['type']
                : 'simple';
            $new_product_id = $this->resolveProductId($old_product_id, $state);

            if (!$old_product_id || !$new_product_id) {
                ++$state['stats']['images_skipped'];
                $this->logger->warning("Skipped product images for old={$old_product_id} because product is not mapped.");
                $this->state->save($state);
                continue;
            }

            $images = is_array($item['images'] ?? null) ? $item['images'] : [];

            if (empty($images)) {
                ++$state['stats']['images_skipped'];
                $this->logger->log("No images for product {$product_name} old={$old_product_id}");
                $this->state->save($state);
            } elseif ($dry_run) {
                $this->logger->log("[DRY] Product {$product_name} old={$old_product_id} -> new={$new_product_id} | images=" . count($images));
            } else {
                $result = $this->image_importer->syncProductImages(
                    $new_product_id,
                    $images,
                    "product {$product_name} old={$old_product_id}"
                );
                $state['stats']['images_downloaded'] += $result['downloaded'];
                $state['stats']['images_failed'] += $result['failed'];
                $this->state->save($state);
            }

            if ($product_type !== 'variable') {
                continue;
            }

            $variations = $this->fetchAllVariations($old_product_id);

            if ($dry_run) {
                $this->logger->log("[DRY] Variable product {$product_name} old={$old_product_id} -> variations=" . count($variations));
                continue;
            }

            foreach ($variations as $variation_item) {
                $old_variation_id = (int) ($variation_item['id'] ?? 0);
                $new_variation_id = $this->resolveVariationId($old_variation_id, $state);

                if (!$old_variation_id || !$new_variation_id) {
                    ++$state['stats']['images_skipped'];
                    $this->logger->warning("Skipped variation image for old={$old_variation_id} because variation is not mapped.");
                    $this->state->save($state);
                    continue;
                }

                $image = is_array($variation_item['image'] ?? null) ? $variation_item['image'] : [];
                if (empty($image['src'])) {
                    continue;
                }

                $result = $this->image_importer->syncVariationImage(
                    $new_variation_id,
                    $image,
                    $product_name . ' variation old=' . $old_variation_id
                );
                $state['stats']['images_downloaded'] += $result['downloaded'];
                $state['stats']['images_failed'] += $result['failed'];
                $this->state->save($state);
            }
        }

        $this->logger->log('Images sync completed for this batch.');
    }

    /**
     * Fetches every variation page for a variable product.
     *
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllVariations(int $product_id): array
    {
        $variations = [];
        $page       = 1;
        $per_page   = 100;

        do {
            $batch = $this->client->get("products/{$product_id}/variations", [
                'page'     => $page,
                'per_page' => $per_page,
                'orderby'  => 'id',
                'order'    => 'asc',
            ]);

            if (empty($batch)) {
                break;
            }

            $variations = array_merge($variations, $batch);
            ++$page;
        } while (count($batch) === $per_page);

        return $variations;
    }

    /**
     * Resolves a migrated parent product ID.
     *
     * @param array<string, mixed> $state Plugin state.
     */
    private function resolveProductId(int $old_product_id, array &$state): int
    {
        if (!empty($state['product_map'][$old_product_id])) {
            return (int) $state['product_map'][$old_product_id];
        }

        $posts = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::PRODUCT_META_KEY,
            'meta_value'     => (string) $old_product_id,
        ]);

        if (empty($posts[0])) {
            return 0;
        }

        $state['product_map'][$old_product_id] = (int) $posts[0];

        return (int) $posts[0];
    }

    /**
     * Resolves a migrated variation ID.
     *
     * @param array<string, mixed> $state Plugin state.
     */
    private function resolveVariationId(int $old_variation_id, array &$state): int
    {
        if (!empty($state['variation_map'][$old_variation_id])) {
            return (int) $state['variation_map'][$old_variation_id];
        }

        $posts = get_posts([
            'post_type'      => 'product_variation',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::VARIATION_META_KEY,
            'meta_value'     => (string) $old_variation_id,
        ]);

        if (empty($posts[0])) {
            return 0;
        }

        $state['variation_map'][$old_variation_id] = (int) $posts[0];

        return (int) $posts[0];
    }
}
