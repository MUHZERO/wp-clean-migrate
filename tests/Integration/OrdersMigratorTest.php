<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Integration;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;
use MuhCleanMigrator\Migrators\OrdersMigrator;
use MuhCleanMigrator\Tests\Fixtures\ApiFixtures;
use MuhCleanMigrator\Tests\TestCase;

final class OrdersMigratorTest extends TestCase
{
    public function test_paid_order_is_created_and_meta_is_copied(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Clean Whey');
        $product_id = $product->save();

        (new State())->save([
            'category_map' => [],
            'product_map'  => [101 => $product_id],
            'variation_map'=> [],
            'customer_map' => [401 => 10],
            'order_map'    => [],
            'stats'        => [],
        ]);

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::order()]);

        $migrator = new OrdersMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $state      = (new State())->get();
        $new_order_id = $state['order_map'][501];
        $order      = wc_get_product($new_order_id);

        $this->assertInstanceOf(\WC_Order::class, $order);
        $this->assertSame('legacy', get_post_meta($new_order_id, '_muh_order_source', true));
        $this->assertSame(1, $state['stats']['orders_created']);
    }

    public function test_existing_order_is_skipped_idempotently(): void
    {
        $existing = wc_create_order(['status' => 'processing']);
        $existing->update_meta_data('_muh_old_order_id', 501);
        $existing->save();

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::order()]);

        $migrator = new OrdersMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $state = (new State())->get();

        $this->assertSame($existing->get_id(), $state['order_map'][501]);
        $this->assertSame(1, $state['stats']['orders_skipped']);
    }

    public function test_order_without_mapped_products_is_skipped_safely(): void
    {
        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::order()]);

        $migrator = new OrdersMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $state = (new State())->get();

        $this->assertSame(1, $state['stats']['orders_skipped']);
        $this->assertNotEmpty(\WP_CLI::$warnings);
    }
}
