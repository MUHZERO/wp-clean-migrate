<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Migrators;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;

/**
 * Migrates simple and variable products from the old store.
 */
final class ProductsMigrator
{
    private const PRODUCT_META_KEY = '_muh_old_product_id';
    private const VARIATION_META_KEY = '_muh_old_variation_id';

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
     * Syncs product batches from the old store.
     *
     * @param array<string, mixed> $assoc_args CLI args.
     */
    public function sync(array $assoc_args): void
    {
        $state    = $this->state->get();
        $page     = max(1, (int) ($assoc_args['page'] ?? 1));
        $per_page = min(100, max(1, (int) ($assoc_args['per-page'] ?? 10)));
        $dry_run  = !empty($assoc_args['dry-run']);

        $this->logger->log("Syncing products | page={$page} per_page={$per_page} dry_run=" . ($dry_run ? 'yes' : 'no'));

        $items = $this->client->get('products', [
            'page'     => $page,
            'per_page' => $per_page,
            'orderby'  => 'id',
            'order'    => 'asc',
            'status'   => 'any',
        ]);

        if (empty($items)) {
            $this->logger->log('No products returned.');
            return;
        }

        foreach ($items as $item) {
            $old_id = (int) ($item['id'] ?? 0);
            $name   = $this->sanitizer->cleanText((string) ($item['name'] ?? ''));
            $type   = $this->normalizeProductType((string) ($item['type'] ?? 'simple'));

            if (!in_array($type, ['simple', 'variable'], true)) {
                $this->logger->warning("Skipping unsupported product: {$name} | type={$type}");
                continue;
            }

            if ($dry_run) {
                if ($type === 'variable') {
                    $variations = $this->fetchAllVariations($old_id);
                    $this->logger->log("[DRY] Variable product {$name} | old={$old_id} | variations=" . count($variations));
                } else {
                    $this->logger->log(
                        "[DRY] Product {$name} | old={$old_id} | sku=" . wc_clean((string) ($item['sku'] ?? ''))
                    );
                }
                continue;
            }

            if ($type === 'variable') {
                $this->syncVariableProduct($item, $state);
                continue;
            }

            $this->syncSimpleProduct($item, $state);
        }

        $this->logger->log('Products sync completed for this batch.');
    }

    /**
     * Syncs one simple product.
     *
     * @param array<string, mixed> $item Old product payload.
     * @param array<string, mixed> $state Plugin state.
     */
    private function syncSimpleProduct(array $item, array &$state): void
    {
        $old_id       = (int) $item['id'];
        $name         = $this->sanitizer->cleanText((string) ($item['name'] ?? ''));
        $slug         = sanitize_title((string) (!empty($item['slug']) ? $item['slug'] : $name));
        $sku          = wc_clean((string) ($item['sku'] ?? ''));
        $resolved     = $this->resolveProduct($old_id, $sku, $slug, 'simple', $state);
        $product      = $resolved['product'];
        $is_new       = $resolved['is_new'];
        $category_ids = $this->mapCategoryIds((array) ($item['categories'] ?? []), $state);

        $this->applyCommonProductFields($product, $item);
        $this->applySimpleProductFields($product, $item);

        $new_id = $product->save();
        $this->persistMigratedProductMeta($new_id, $old_id);

        if (!empty($category_ids)) {
            wp_set_object_terms($new_id, $category_ids, 'product_cat');
        }

        $state['product_map'][$old_id] = $new_id;

        if ($is_new) {
            ++$state['stats']['products_created'];
            $this->logger->log("Created product: {$name} (old={$old_id}, new={$new_id})");
        } else {
            ++$state['stats']['products_updated'];
            $this->logger->log("Updated product: {$name} (old={$old_id}, new={$new_id})");
        }

        $this->state->save($state);
    }

    /**
     * Syncs one variable parent and all of its variations.
     *
     * @param array<string, mixed> $item Old product payload.
     * @param array<string, mixed> $state Plugin state.
     */
    private function syncVariableProduct(array $item, array &$state): void
    {
        $old_id       = (int) $item['id'];
        $name         = $this->sanitizer->cleanText((string) ($item['name'] ?? ''));
        $slug         = sanitize_title((string) (!empty($item['slug']) ? $item['slug'] : $name));
        $sku          = wc_clean((string) ($item['sku'] ?? ''));
        $variations   = $this->fetchAllVariations($old_id);
        $definitions  = $this->buildVariableAttributeDefinitions($item, $variations);
        $resolved     = $this->resolveProduct($old_id, $sku, $slug, 'variable', $state);
        $product      = $resolved['product'];
        $is_new       = $resolved['is_new'];
        $category_ids = $this->mapCategoryIds((array) ($item['categories'] ?? []), $state);

        $this->applyCommonProductFields($product, $item);
        $product->set_regular_price('');
        $product->set_sale_price('');
        $product->set_attributes($this->buildVariableAttributes($definitions));
        $product->set_default_attributes($this->buildDefaultAttributes($item, $definitions));

        $new_id = $product->save();
        $this->persistMigratedProductMeta($new_id, $old_id);

        if (!empty($category_ids)) {
            wp_set_object_terms($new_id, $category_ids, 'product_cat');
        }

        $state['product_map'][$old_id] = $new_id;

        if ($is_new) {
            ++$state['stats']['products_created'];
            $this->logger->log("Created variable product: {$name} (old={$old_id}, new={$new_id})");
        } else {
            ++$state['stats']['products_updated'];
            $this->logger->log("Updated variable product: {$name} (old={$old_id}, new={$new_id})");
        }

        $this->state->save($state);
        $this->syncVariations($new_id, $old_id, $variations, $definitions, $state);

        \WC_Product_Variable::sync($new_id);

        $synced_product = wc_get_product($new_id);
        if ($synced_product instanceof \WC_Product_Variable) {
            $synced_product->save();
        }

        $this->state->save($state);
    }

    /**
     * Syncs all variations for a variable product.
     *
     * @param array<int, array<string, mixed>> $variations Variation payloads.
     * @param array<string, array<string, mixed>> $definitions Parent attribute definitions.
     * @param array<string, mixed> $state Plugin state.
     */
    private function syncVariations(
        int $parent_id,
        int $parent_old_id,
        array $variations,
        array $definitions,
        array &$state
    ): void {
        foreach ($variations as $variation_item) {
            $old_variation_id = (int) ($variation_item['id'] ?? 0);
            if (!$old_variation_id) {
                continue;
            }

            $resolved        = $this->resolveVariation($old_variation_id, $parent_id, $variation_item, $definitions, $state);
            $variation       = $resolved['variation'];
            $is_new          = $resolved['is_new'];
            $variation_label = $this->buildVariationLabel($variation_item, $old_variation_id);
            $sku             = wc_clean((string) ($variation_item['sku'] ?? ''));

            $variation->set_parent_id($parent_id);
            $variation->set_status($this->normalizeVariationStatus((string) ($variation_item['status'] ?? 'publish')));

            if ($sku !== '') {
                $variation->set_sku($sku);
            }

            if (isset($variation_item['regular_price']) && $variation_item['regular_price'] !== '') {
                $variation->set_regular_price((string) $variation_item['regular_price']);
            } else {
                $variation->set_regular_price('');
            }

            if (isset($variation_item['sale_price']) && $variation_item['sale_price'] !== '') {
                $variation->set_sale_price((string) $variation_item['sale_price']);
            } else {
                $variation->set_sale_price('');
            }

            $variation->set_manage_stock(!empty($variation_item['manage_stock']));

            if (!empty($variation_item['manage_stock']) && isset($variation_item['stock_quantity']) && $variation_item['stock_quantity'] !== null) {
                $variation->set_stock_quantity((int) $variation_item['stock_quantity']);
            } else {
                $variation->set_stock_quantity(null);
            }

            $variation->set_stock_status((string) ($variation_item['stock_status'] ?? 'instock'));
            $variation->set_virtual(!empty($variation_item['virtual']));
            $variation->set_downloadable(!empty($variation_item['downloadable']));
            $variation->set_attributes($this->buildVariationAttributes($variation_item, $definitions));

            $new_variation_id = $variation->save();
            $this->persistMigratedVariationMeta($new_variation_id, $old_variation_id, $parent_old_id);

            $state['variation_map'][$old_variation_id] = $new_variation_id;

            if ($is_new) {
                ++$state['stats']['variations_created'];
                $this->logger->log(
                    "Created variation: {$variation_label} (old={$old_variation_id}, new={$new_variation_id})"
                );
            } else {
                ++$state['stats']['variations_updated'];
                $this->logger->log(
                    "Updated variation: {$variation_label} (old={$old_variation_id}, new={$new_variation_id})"
                );
            }

            $this->state->save($state);
        }
    }

    /**
     * Applies shared product fields to simple and variable parent products.
     *
     * @param array<string, mixed> $item Old product payload.
     */
    private function applyCommonProductFields(\WC_Product $product, array $item): void
    {
        $name        = $this->sanitizer->cleanText((string) ($item['name'] ?? ''));
        $slug        = sanitize_title((string) (!empty($item['slug']) ? $item['slug'] : $name));
        $description = $this->sanitizer->cleanHtml((string) ($item['description'] ?? ''));
        $short_desc  = $this->sanitizer->cleanHtml((string) ($item['short_description'] ?? ''));
        $status      = in_array((string) ($item['status'] ?? 'publish'), ['draft', 'pending', 'private', 'publish'], true)
            ? (string) $item['status']
            : 'publish';
        $sku         = wc_clean((string) ($item['sku'] ?? ''));

        $product->set_name($name);
        $product->set_slug($slug);
        $product->set_status($status);
        $product->set_catalog_visibility((string) ($item['catalog_visibility'] ?? 'visible'));
        $product->set_description($description);
        $product->set_short_description($short_desc);
        $product->set_featured(!empty($item['featured']));
        $product->set_manage_stock(!empty($item['manage_stock']));

        if (!empty($item['manage_stock']) && isset($item['stock_quantity']) && $item['stock_quantity'] !== null) {
            $product->set_stock_quantity((int) $item['stock_quantity']);
        } else {
            $product->set_stock_quantity(null);
        }

        $product->set_stock_status((string) ($item['stock_status'] ?? 'instock'));
        $product->set_virtual(!empty($item['virtual']));
        $product->set_downloadable(!empty($item['downloadable']));

        if ($sku !== '') {
            $product->set_sku($sku);
        }
    }

    /**
     * Applies simple-product specific fields.
     *
     * @param array<string, mixed> $item Old product payload.
     */
    private function applySimpleProductFields(\WC_Product $product, array $item): void
    {
        if (isset($item['regular_price']) && $item['regular_price'] !== '') {
            $product->set_regular_price((string) $item['regular_price']);
        } else {
            $product->set_regular_price('');
        }

        if (isset($item['sale_price']) && $item['sale_price'] !== '') {
            $product->set_sale_price((string) $item['sale_price']);
        } else {
            $product->set_sale_price('');
        }
    }

    /**
     * Resolves an existing product or prepares a new one.
     *
     * @param array<string, mixed> $state Plugin state.
     * @return array{product:\WC_Product,is_new:bool}
     */
    private function resolveProduct(int $old_id, string $sku, string $slug, string $type, array $state): array
    {
        $product_id = 0;

        if (!empty($state['product_map'][$old_id])) {
            $product_id = (int) $state['product_map'][$old_id];
        }

        if (!$product_id) {
            $product_id = $this->findProductIdByOldId($old_id);
        }

        if (!$product_id && $sku !== '') {
            $matched_id = wc_get_product_id_by_sku($sku);
            if ($matched_id && get_post_type($matched_id) === 'product') {
                $product_id = (int) $matched_id;
            }
        }

        if (!$product_id && $slug !== '') {
            $existing = get_page_by_path($slug, OBJECT, 'product');
            if ($existing) {
                $product_id = (int) $existing->ID;
            }
        }

        if ($product_id > 0) {
            return [
                'product' => $this->instantiateProduct($product_id, $type),
                'is_new'  => false,
            ];
        }

        return [
            'product' => $type === 'variable' ? new \WC_Product_Variable() : new \WC_Product_Simple(),
            'is_new'  => true,
        ];
    }

    /**
     * Resolves an existing variation or prepares a new one.
     *
     * @param array<string, mixed> $variation_item Old variation payload.
     * @param array<string, array<string, mixed>> $definitions Parent attribute definitions.
     * @param array<string, mixed> $state Plugin state.
     * @return array{variation:\WC_Product_Variation,is_new:bool}
     */
    private function resolveVariation(
        int $old_variation_id,
        int $parent_id,
        array $variation_item,
        array $definitions,
        array $state
    ): array {
        $variation_id = 0;

        if (!empty($state['variation_map'][$old_variation_id])) {
            $variation_id = (int) $state['variation_map'][$old_variation_id];
        }

        if (!$variation_id) {
            $variation_id = $this->findVariationIdByOldId($old_variation_id);
        }

        $sku = wc_clean((string) ($variation_item['sku'] ?? ''));
        if (!$variation_id && $sku !== '') {
            $matched_id = wc_get_product_id_by_sku($sku);
            if ($matched_id && get_post_type($matched_id) === 'product_variation') {
                $variation_id = (int) $matched_id;
            }
        }

        if (!$variation_id) {
            $attributes   = $this->buildVariationAttributes($variation_item, $definitions);
            $variation_id = $this->findVariationIdByAttributes($parent_id, $attributes);
        }

        if ($variation_id > 0) {
            return [
                'variation' => new \WC_Product_Variation($variation_id),
                'is_new'    => false,
            ];
        }

        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($parent_id);

        return [
            'variation' => $variation,
            'is_new'    => true,
        ];
    }

    private function instantiateProduct(int $product_id, string $type): \WC_Product
    {
        wp_set_object_terms($product_id, $type, 'product_type');
        wc_delete_product_transients($product_id);

        if ($type === 'variable') {
            return new \WC_Product_Variable($product_id);
        }

        return new \WC_Product_Simple($product_id);
    }

    private function findProductIdByOldId(int $old_id): int
    {
        $posts = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::PRODUCT_META_KEY,
            'meta_value'     => (string) $old_id,
        ]);

        return !empty($posts[0]) ? (int) $posts[0] : 0;
    }

    private function findVariationIdByOldId(int $old_id): int
    {
        $posts = get_posts([
            'post_type'      => 'product_variation',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => self::VARIATION_META_KEY,
            'meta_value'     => (string) $old_id,
        ]);

        return !empty($posts[0]) ? (int) $posts[0] : 0;
    }

    /**
     * Finds a variation under the parent by its attribute combination.
     *
     * @param array<string, string> $attributes Variation attribute values.
     */
    private function findVariationIdByAttributes(int $parent_id, array $attributes): int
    {
        $parent = wc_get_product($parent_id);
        if (!$parent instanceof \WC_Product_Variable) {
            return 0;
        }

        $expected = $this->normalizeVariationAttributeMap($attributes);

        foreach ($parent->get_children() as $child_id) {
            $child = wc_get_product($child_id);
            if (!$child instanceof \WC_Product_Variation) {
                continue;
            }

            if ($this->normalizeVariationAttributeMap($child->get_attributes()) === $expected) {
                return (int) $child_id;
            }
        }

        return 0;
    }

    /**
     * Builds parent attribute definitions using parent and child data.
     *
     * @param array<string, mixed> $item Old product payload.
     * @param array<int, array<string, mixed>> $variations Variation payloads.
     * @return array<string, array<string, mixed>>
     */
    private function buildVariableAttributeDefinitions(array $item, array $variations): array
    {
        $definitions = [];

        foreach ((array) ($item['attributes'] ?? []) as $attribute) {
            $definition = $this->makeAttributeDefinition($attribute);
            if (!$definition) {
                continue;
            }

            $definitions[(string) $definition['slug']] = $definition;
        }

        foreach ($variations as $variation) {
            foreach ((array) ($variation['attributes'] ?? []) as $attribute) {
                $definition = $this->makeAttributeDefinition($attribute, true);
                if (!$definition) {
                    continue;
                }

                $key = (string) $definition['slug'];

                if (!isset($definitions[$key])) {
                    $definitions[$key] = $definition;
                    continue;
                }

                $definitions[$key]['variation'] = true;
                $definitions[$key]['options'] = array_values(
                    array_unique(
                        array_merge(
                            (array) $definitions[$key]['options'],
                            (array) $definition['options']
                        )
                    )
                );
            }
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $attribute Old attribute payload.
     * @return array<string, mixed>|null
     */
    private function makeAttributeDefinition(array $attribute, bool $force_variation = false): ?array
    {
        $name = $this->sanitizer->cleanText((string) ($attribute['name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $options = [];

        foreach ((array) ($attribute['options'] ?? []) as $option) {
            $clean_option = $this->sanitizer->cleanText((string) $option);
            if ($clean_option !== '') {
                $options[] = $clean_option;
            }
        }

        $single_option = $this->sanitizer->cleanText((string) ($attribute['option'] ?? ''));
        if ($single_option !== '') {
            $options[] = $single_option;
        }

        return [
            'name'      => $name,
            'slug'      => sanitize_title($name),
            'options'   => array_values(array_unique($options)),
            'visible'   => !empty($attribute['visible']) || !empty($attribute['variation']) || $force_variation,
            'variation' => !empty($attribute['variation']) || $force_variation,
        ];
    }

    /**
     * Builds WooCommerce parent attributes.
     *
     * @param array<string, array<string, mixed>> $definitions Parent attribute definitions.
     * @return array<int, \WC_Product_Attribute>
     */
    private function buildVariableAttributes(array $definitions): array
    {
        $attributes = [];
        $position   = 0;

        foreach ($definitions as $definition) {
            $attribute = new \WC_Product_Attribute();
            $attribute->set_id(0);
            $attribute->set_name((string) $definition['name']);
            $attribute->set_options((array) $definition['options']);
            $attribute->set_position($position);
            $attribute->set_visible(!empty($definition['visible']));
            $attribute->set_variation(!empty($definition['variation']));
            $attributes[] = $attribute;
            ++$position;
        }

        return $attributes;
    }

    /**
     * Builds default attributes for a variable product.
     *
     * @param array<string, mixed> $item Old product payload.
     * @param array<string, array<string, mixed>> $definitions Parent attribute definitions.
     * @return array<string, string>
     */
    private function buildDefaultAttributes(array $item, array $definitions): array
    {
        $defaults = [];

        foreach ((array) ($item['default_attributes'] ?? []) as $attribute) {
            $name   = $this->sanitizer->cleanText((string) ($attribute['name'] ?? ''));
            $option = $this->sanitizer->cleanText((string) ($attribute['option'] ?? ''));
            $slug   = sanitize_title($name);

            if ($slug === '' || $option === '' || !isset($definitions[$slug])) {
                continue;
            }

            $defaults[$slug] = $option;
        }

        return $defaults;
    }

    /**
     * Builds variation attribute values.
     *
     * @param array<string, mixed> $variation_item Old variation payload.
     * @param array<string, array<string, mixed>> $definitions Parent attribute definitions.
     * @return array<string, string>
     */
    private function buildVariationAttributes(array $variation_item, array $definitions): array
    {
        $attributes = [];

        foreach ((array) ($variation_item['attributes'] ?? []) as $attribute) {
            $name   = $this->sanitizer->cleanText((string) ($attribute['name'] ?? ''));
            $option = $this->sanitizer->cleanText((string) ($attribute['option'] ?? ''));
            $slug   = sanitize_title($name);

            if ($slug === '' || $option === '' || !isset($definitions[$slug])) {
                continue;
            }

            $attributes[$slug] = $option;
        }

        return $attributes;
    }

    /**
     * Maps old category IDs to migrated category IDs.
     *
     * @param array<int, array<string, mixed>> $categories Old category payloads.
     * @param array<string, mixed> $state Plugin state.
     * @return array<int, int>
     */
    private function mapCategoryIds(array $categories, array $state): array
    {
        $category_ids = [];

        foreach ($categories as $category) {
            $old_cat_id = (int) ($category['id'] ?? 0);
            if (!empty($state['category_map'][$old_cat_id])) {
                $category_ids[] = (int) $state['category_map'][$old_cat_id];
            }
        }

        return array_values(array_unique($category_ids));
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

    private function persistMigratedProductMeta(int $product_id, int $old_id): void
    {
        update_post_meta($product_id, self::PRODUCT_META_KEY, (string) $old_id);
        update_post_meta($product_id, '_muh_migrated_from', 'old_store');
    }

    private function persistMigratedVariationMeta(int $variation_id, int $old_id, int $parent_old_id): void
    {
        update_post_meta($variation_id, self::VARIATION_META_KEY, (string) $old_id);
        update_post_meta($variation_id, '_muh_old_parent_product_id', (string) $parent_old_id);
        update_post_meta($variation_id, '_muh_migrated_from', 'old_store');
    }

    /**
     * Normalizes variation attributes for matching.
     *
     * @param array<string, string> $attributes Variation attributes.
     * @return array<string, string>
     */
    private function normalizeVariationAttributeMap(array $attributes): array
    {
        $normalized = [];

        foreach ($attributes as $key => $value) {
            $normalized[sanitize_title((string) $key)] = strtolower(trim((string) $value));
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Builds a readable variation label for logs.
     *
     * @param array<string, mixed> $variation_item Old variation payload.
     */
    private function buildVariationLabel(array $variation_item, int $old_variation_id): string
    {
        $parts = [];

        foreach ((array) ($variation_item['attributes'] ?? []) as $attribute) {
            $name   = $this->sanitizer->cleanText((string) ($attribute['name'] ?? ''));
            $option = $this->sanitizer->cleanText((string) ($attribute['option'] ?? ''));

            if ($name !== '' && $option !== '') {
                $parts[] = $name . '=' . $option;
            }
        }

        return !empty($parts) ? implode(', ', $parts) : 'variation-' . $old_variation_id;
    }

    private function normalizeProductType(string $type): string
    {
        return in_array($type, ['simple', 'external', 'grouped', 'variable'], true) ? $type : 'simple';
    }

    private function normalizeVariationStatus(string $status): string
    {
        return in_array($status, ['private', 'publish'], true) ? $status : 'publish';
    }
}
