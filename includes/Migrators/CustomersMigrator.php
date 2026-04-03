<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Migrators;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;

/**
 * Migrates customers from the old store.
 */
final class CustomersMigrator
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
     * Syncs customer batches from the old store.
     *
     * @param array<string, mixed> $assoc_args CLI args.
     */
    public function sync(array $assoc_args): void
    {
        $state    = $this->state->get();
        $page     = max(1, (int) ($assoc_args['page'] ?? 1));
        $per_page = min(100, max(1, (int) ($assoc_args['per-page'] ?? 20)));
        $dry_run  = !empty($assoc_args['dry-run']);

        $this->logger->log("Syncing customers | page={$page} per_page={$per_page} dry_run=" . ($dry_run ? 'yes' : 'no'));

        $items = $this->client->get('customers', [
            'page'     => $page,
            'per_page' => $per_page,
            'orderby'  => 'id',
            'order'    => 'asc',
        ]);

        if (empty($items)) {
            $this->logger->log('No customers returned.');
            return;
        }

        foreach ($items as $item) {
            $old_id   = (int) $item['id'];
            $email    = sanitize_email((string) ($item['email'] ?? ''));
            $username = sanitize_user((string) ($item['username'] ?? ''), true);
            $first    = $this->sanitizer->cleanText((string) ($item['first_name'] ?? ''));
            $last     = $this->sanitizer->cleanText((string) ($item['last_name'] ?? ''));

            if (!$email || !is_email($email)) {
                $this->logger->warning("Skipping customer old={$old_id} because email is invalid.");
                continue;
            }

            $billing  = is_array($item['billing'] ?? null) ? $item['billing'] : [];
            $shipping = is_array($item['shipping'] ?? null) ? $item['shipping'] : [];

            if ($dry_run) {
                $this->logger->log("[DRY] Customer {$email} | old={$old_id}");
                continue;
            }

            $user   = get_user_by('email', $email);
            $is_new = false;

            if (!$user) {
                $username = $this->resolveUsername($email, $username);
                $user_id  = wp_create_user($username, wp_generate_password(20, true, true), $email);

                if (is_wp_error($user_id)) {
                    $this->logger->warning("Failed creating customer {$email}: " . $user_id->get_error_message());
                    continue;
                }

                $user   = get_user_by('id', $user_id);
                $is_new = true;
            }

            if (!$user) {
                $this->logger->warning("Could not load created/found user for {$email}");
                continue;
            }

            wp_update_user([
                'ID'           => $user->ID,
                'first_name'   => $first,
                'last_name'    => $last,
                'display_name' => trim($first . ' ' . $last) ?: $user->user_login,
                'role'         => 'customer',
            ]);

            $this->updateCustomerMeta((int) $user->ID, $billing, $shipping);

            $state['customer_map'][$old_id] = (int) $user->ID;

            if ($is_new) {
                ++$state['stats']['customers_created'];
                $this->logger->log("Created customer: {$email} (old={$old_id}, new={$user->ID})");
            } else {
                ++$state['stats']['customers_updated'];
                $this->logger->log("Updated customer: {$email} (old={$old_id}, new={$user->ID})");
            }

            $this->state->save($state);
        }

        $this->logger->log('Customers sync completed for this batch.');
    }

    private function resolveUsername(string $email, string $username): string
    {
        if ($username === '') {
            $username = sanitize_user((string) current(explode('@', $email)), true);
        }

        $base_username = $username ?: 'customer';
        $username      = $base_username;
        $suffix        = 1;

        while (username_exists($username)) {
            $username = $base_username . $suffix;
            ++$suffix;
        }

        return $username;
    }

    /**
     * Persists billing and shipping metadata for a migrated customer.
     *
     * @param array<string, mixed> $billing  Billing payload.
     * @param array<string, mixed> $shipping Shipping payload.
     */
    private function updateCustomerMeta(int $user_id, array $billing, array $shipping): void
    {
        $map = [
            'billing_first_name',
            'billing_last_name',
            'billing_company',
            'billing_address_1',
            'billing_address_2',
            'billing_city',
            'billing_state',
            'billing_postcode',
            'billing_country',
            'billing_email',
            'billing_phone',
            'shipping_first_name',
            'shipping_last_name',
            'shipping_company',
            'shipping_address_1',
            'shipping_address_2',
            'shipping_city',
            'shipping_state',
            'shipping_postcode',
            'shipping_country',
        ];

        $flat = [
            'billing_first_name'  => $billing['first_name'] ?? '',
            'billing_last_name'   => $billing['last_name'] ?? '',
            'billing_company'     => $billing['company'] ?? '',
            'billing_address_1'   => $billing['address_1'] ?? '',
            'billing_address_2'   => $billing['address_2'] ?? '',
            'billing_city'        => $billing['city'] ?? '',
            'billing_state'       => $billing['state'] ?? '',
            'billing_postcode'    => $billing['postcode'] ?? '',
            'billing_country'     => $billing['country'] ?? '',
            'billing_email'       => $billing['email'] ?? '',
            'billing_phone'       => $billing['phone'] ?? '',
            'shipping_first_name' => $shipping['first_name'] ?? '',
            'shipping_last_name'  => $shipping['last_name'] ?? '',
            'shipping_company'    => $shipping['company'] ?? '',
            'shipping_address_1'  => $shipping['address_1'] ?? '',
            'shipping_address_2'  => $shipping['address_2'] ?? '',
            'shipping_city'       => $shipping['city'] ?? '',
            'shipping_state'      => $shipping['state'] ?? '',
            'shipping_postcode'   => $shipping['postcode'] ?? '',
            'shipping_country'    => $shipping['country'] ?? '',
        ];

        foreach ($map as $meta_key) {
            update_user_meta($user_id, $meta_key, wc_clean((string) ($flat[$meta_key] ?? '')));
        }
    }
}
