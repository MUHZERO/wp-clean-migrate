<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Migrators;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;

/**
 * Migrates paid WooCommerce orders from the old store.
 */
final class OrdersMigrator
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
     * Syncs paid orders from the old store.
     *
     * @param array<string, mixed> $assoc_args CLI args.
     */
    public function sync(array $assoc_args): void
    {
        $state    = $this->state->get();
        $page     = max(1, (int) ($assoc_args['page'] ?? 1));
        $per_page = min(50, max(1, (int) ($assoc_args['per-page'] ?? 10)));
        $dry_run  = !empty($assoc_args['dry-run']);

        $this->logger->log("Syncing paid orders | page={$page} per_page={$per_page} dry_run=" . ($dry_run ? 'yes' : 'no'));

        $items = $this->client->get('orders', [
            'page'     => $page,
            'per_page' => $per_page,
            'orderby'  => 'id',
            'order'    => 'asc',
            'status'   => 'processing,completed',
        ]);

        if (empty($items)) {
            $this->logger->log('No paid orders returned.');
            return;
        }

        foreach ($items as $item) {
            $old_order_id = (int) ($item['id'] ?? 0);
            if (!$old_order_id) {
                continue;
            }

            $status = sanitize_key((string) ($item['status'] ?? ''));
            if (!in_array($status, ['processing', 'completed'], true)) {
                ++$state['stats']['orders_skipped'];
                $this->logger->log("Skipped order old={$old_order_id} because status={$status}");
                $this->state->save($state);
                continue;
            }

            $existing_new_order_id = $this->findExistingOrderByOldId($old_order_id);
            if (!empty($state['order_map'][$old_order_id]) || $existing_new_order_id) {
                $mapped_id = !empty($state['order_map'][$old_order_id])
                    ? (int) $state['order_map'][$old_order_id]
                    : $existing_new_order_id;

                $state['order_map'][$old_order_id] = $mapped_id;
                ++$state['stats']['orders_skipped'];
                $this->logger->log("Skipped existing order old={$old_order_id} -> new={$mapped_id}");
                $this->state->save($state);
                continue;
            }

            $billing  = is_array($item['billing'] ?? null) ? $item['billing'] : [];
            $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];

            $customer_id     = $this->resolveNewCustomerId($item, $state);
            $line_items_data = $this->prepareOrderLineItems($item, $state);

            if (empty($line_items_data)) {
                ++$state['stats']['orders_skipped'];
                $this->logger->warning("Skipped order old={$old_order_id} because no valid line items matched migrated products.");
                $this->state->save($state);
                continue;
            }

            if ($dry_run) {
                $this->logger->log("[DRY] Order old={$old_order_id} status={$status} items=" . count($line_items_data));
                continue;
            }

            try {
                $order = wc_create_order([
                    'customer_id' => $customer_id ?: 0,
                    'status'      => $status,
                    'created_via' => 'muh_clean_migrator',
                ]);
            } catch (\Throwable $exception) {
                $this->logger->warning("Failed creating order old={$old_order_id}: " . $exception->getMessage());
                continue;
            }

            if (!$order || is_wp_error($order)) {
                $this->logger->warning("Failed creating order old={$old_order_id}");
                continue;
            }

            foreach ($line_items_data as $prepared_item) {
                $product = wc_get_product($prepared_item['product_id']);

                if (!$product) {
                    $this->logger->warning("Missing mapped product {$prepared_item['product_id']} for order old={$old_order_id}");
                    continue;
                }

                $item_id = $order->add_product($product, $prepared_item['quantity'], [
                    'subtotal' => $prepared_item['subtotal'],
                    'total'    => $prepared_item['total'],
                ]);

                if ($item_id && !empty($prepared_item['meta_data'])) {
                    $order_item = $order->get_item($item_id);
                    if ($order_item) {
                        foreach ($prepared_item['meta_data'] as $meta_pair) {
                            if (!empty($meta_pair['key'])) {
                                $order_item->add_meta_data((string) $meta_pair['key'], $meta_pair['value'], true);
                            }
                        }
                        $order_item->save();
                    }
                }
            }

            $this->applyOrderAddress($order, 'billing', $billing);
            $this->applyOrderAddress($order, 'shipping', $shipping);

            $payment_method       = wc_clean((string) ($item['payment_method'] ?? ''));
            $payment_method_title = $this->sanitizer->cleanText((string) ($item['payment_method_title'] ?? ''));

            if ($payment_method !== '') {
                $order->set_payment_method($payment_method);
            }

            if ($payment_method_title !== '') {
                $order->set_payment_method_title($payment_method_title);
            }

            $currency = strtoupper(wc_clean((string) ($item['currency'] ?? '')));
            if ($currency !== '') {
                $order->set_currency($currency);
            }

            $created_at = (string) ($item['date_created'] ?? '');
            if ($created_at !== '') {
                $timestamp = strtotime($created_at);
                if ($timestamp) {
                    $order->set_date_created($timestamp);
                }
            }

            $old_customer_note = $this->sanitizer->cleanText((string) ($item['customer_note'] ?? ''));
            if ($old_customer_note !== '') {
                $order->set_customer_note($old_customer_note);
            }

            $order->update_meta_data('_muh_old_order_id', $old_order_id);
            $order->update_meta_data('_muh_migrated_from', 'old_store');
            $order->update_meta_data('_muh_old_order_number', (string) ($item['number'] ?? $old_order_id));

            $this->copyWhitelistedOrderMeta($order, $item);

            $order->calculate_totals(false);
            $order->save();

            $new_order_id                    = (int) $order->get_id();
            $state['order_map'][$old_order_id] = $new_order_id;
            ++$state['stats']['orders_created'];

            $this->logger->log("Created order old={$old_order_id} -> new={$new_order_id}");
            $this->state->save($state);
        }

        $this->logger->log('Orders sync completed for this batch.');
    }

    private function findExistingOrderByOldId(int $old_order_id): int
    {
        $query = new \WC_Order_Query([
            'limit'      => 1,
            'return'     => 'ids',
            'meta_key'   => '_muh_old_order_id',
            'meta_value' => (string) $old_order_id,
        ]);

        $results = $query->get_orders();

        return !empty($results[0]) ? (int) $results[0] : 0;
    }

    /**
     * @param array<string, mixed> $item  Old order payload.
     * @param array<string, mixed> $state Saved migration state.
     */
    private function resolveNewCustomerId(array $item, array $state): int
    {
        $old_customer_id = (int) ($item['customer_id'] ?? 0);

        if ($old_customer_id && !empty($state['customer_map'][$old_customer_id])) {
            return (int) $state['customer_map'][$old_customer_id];
        }

        $billing_email = sanitize_email((string) ($item['billing']['email'] ?? ''));
        if ($billing_email && is_email($billing_email)) {
            $user = get_user_by('email', $billing_email);
            if ($user) {
                return (int) $user->ID;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $item  Old order payload.
     * @param array<string, mixed> $state Saved migration state.
     * @return array<int, array<string, mixed>>
     */
    private function prepareOrderLineItems(array $item, array $state): array
    {
        $prepared   = [];
        $line_items = is_array($item['line_items'] ?? null) ? $item['line_items'] : [];

        foreach ($line_items as $line) {
            $old_product_id = (int) ($line['product_id'] ?? 0);
            $variation_id   = (int) ($line['variation_id'] ?? 0);
            $quantity       = max(1, (int) ($line['quantity'] ?? 1));

            if (!$old_product_id || empty($state['product_map'][$old_product_id])) {
                continue;
            }

            $new_product_id = (int) $state['product_map'][$old_product_id];
            $meta_data      = [];

            if (!empty($line['meta_data']) && is_array($line['meta_data'])) {
                foreach ($line['meta_data'] as $meta) {
                    $key = isset($meta['key']) ? wc_clean((string) $meta['key']) : '';
                    $val = isset($meta['value']) ? maybe_serialize($meta['value']) : '';

                    if ($key === '' || $this->isBlockedOrderMetaKey($key)) {
                        continue;
                    }

                    $meta_data[] = [
                        'key'   => $key,
                        'value' => $val,
                    ];
                }
            }

            $prepared[] = [
                'product_id'   => $new_product_id,
                'variation_id' => $variation_id,
                'quantity'     => $quantity,
                'subtotal'     => isset($line['subtotal']) ? (float) $line['subtotal'] : 0.0,
                'total'        => isset($line['total']) ? (float) $line['total'] : 0.0,
                'meta_data'    => $meta_data,
            ];
        }

        return $prepared;
    }

    /**
     * @param array<string, mixed> $data Old address payload.
     */
    private function applyOrderAddress(\WC_Order $order, string $type, array $data): void
    {
        $address = [
            'first_name' => $this->sanitizer->cleanText((string) ($data['first_name'] ?? '')),
            'last_name'  => $this->sanitizer->cleanText((string) ($data['last_name'] ?? '')),
            'company'    => $this->sanitizer->cleanText((string) ($data['company'] ?? '')),
            'email'      => sanitize_email((string) ($data['email'] ?? '')),
            'phone'      => wc_clean((string) ($data['phone'] ?? '')),
            'address_1'  => $this->sanitizer->cleanText((string) ($data['address_1'] ?? '')),
            'address_2'  => $this->sanitizer->cleanText((string) ($data['address_2'] ?? '')),
            'city'       => $this->sanitizer->cleanText((string) ($data['city'] ?? '')),
            'state'      => $this->sanitizer->cleanText((string) ($data['state'] ?? '')),
            'postcode'   => $this->sanitizer->cleanText((string) ($data['postcode'] ?? '')),
            'country'    => strtoupper(wc_clean((string) ($data['country'] ?? ''))),
        ];

        $order->set_address($address, $type);
    }

    /**
     * Copies a controlled subset of order meta from the old store.
     *
     * @param array<string, mixed> $item Old order payload.
     */
    private function copyWhitelistedOrderMeta(\WC_Order $order, array $item): void
    {
        $allowed_meta_keys = [
            '_payment_method',
            '_payment_method_title',
            '_transaction_id',
            '_customer_ip_address',
            '_customer_user_agent',
            '_billing_phone',
            '_billing_email',
            '_shipping_phone',
            '_order_currency',
            '_cart_discount',
            '_cart_discount_tax',
            '_order_shipping',
            '_order_shipping_tax',
            '_order_tax',
            '_order_total',
            '_prices_include_tax',
            '_created_via',
            '_recorded_sales',
            '_recorded_coupon_usage_counts',
            '_muh_order_source',
            '_muh_skip_checkout',
            '_muh_promo_rules_applied',
            '_muh_promo_discount_total',
            '_muh_pixel_initiate_checkout_sent',
            '_muh_fb_pixel_purchase_sent',
            '_muh_tt_pixel_purchase_sent',
            '_muh_google_pixel_purchase_sent',
            '_muh_tracking_json',
            'muh_verified_by',
            'muh_total_matched',
            'muh_shopify_order_id',
            'muh_shopify_topic',
            'muh_shopify_order_number',
            'muh_shopify_confirmation_number',
            'muh_shopify_order_status_url',
            'muh_shopify_financial_status',
            'muh_shopify_fulfillment_status',
            'muh_shopify_order_name',
            'muh_shopify_admin_graphql_id',
            'muh_shopify_cart_token',
            'muh_shopify_checkout_token',
            'muh_shopify_token',
            'muh_shopify_currency',
            'muh_shopify_payment_gateways',
            'muh_shopify_test_flag',
            'muh_shopify_current_total_price',
            'muh_shopify_current_total_tax',
            'muh_shopify_shipping_amount',
            'muh_shopify_browser_ip',
        ];

        $meta_data = is_array($item['meta_data'] ?? null) ? $item['meta_data'] : [];

        foreach ($meta_data as $meta) {
            $key = isset($meta['key']) ? wc_clean((string) $meta['key']) : '';
            $val = $meta['value'] ?? null;

            if ($key === '' || $this->isBlockedOrderMetaKey($key)) {
                continue;
            }

            $is_exactly_allowed = in_array($key, $allowed_meta_keys, true);
            $is_dynamic_muh_key = strpos($key, '_muh_') === 0;

            if (!$is_exactly_allowed && !$is_dynamic_muh_key) {
                continue;
            }

            $order->update_meta_data($key, maybe_serialize($val));
        }
    }

    private function isBlockedOrderMetaKey(string $key): bool
    {
        $blocked_prefixes = [
            '_edit_',
            '_wp_',
            '_yoast_',
            '_rank_math',
            '_aioseo_',
            '_elementor',
        ];

        foreach ($blocked_prefixes as $prefix) {
            if (strpos($key, $prefix) === 0) {
                return true;
            }
        }

        return in_array($key, ['_muh_old_order_id', '_muh_migrated_from', '_muh_old_order_number'], true);
    }
}
