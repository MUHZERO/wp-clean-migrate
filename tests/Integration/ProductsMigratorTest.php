<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Integration;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;
use MuhCleanMigrator\Migrators\ProductsMigrator;
use MuhCleanMigrator\Tests\Fixtures\ApiFixtures;
use MuhCleanMigrator\Tests\Support\FakeWpEnvironment;
use MuhCleanMigrator\Tests\TestCase;

final class ProductsMigratorTest extends TestCase
{
    public function test_simple_product_is_created_and_assigned_categories(): void
    {
        $state = new State();
        $state->save([
            'category_map' => [10 => 55],
            'product_map'  => [],
            'variation_map'=> [],
            'customer_map' => [],
            'order_map'    => [],
            'stats'        => [],
        ]);

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::simpleProduct()]);

        $migrator = new ProductsMigrator($client, $state, new Logger(), new Sanitizer());
        $migrator->sync([]);

        $saved = $state->get();
        $newId = $saved['product_map'][101];
        $product = wc_get_product($newId);

        $this->assertInstanceOf(\WC_Product_Simple::class, $product);
        $this->assertSame('WHEY-001', $product->get_sku());
        $this->assertSame([55], FakeWpEnvironment::getPostMeta($newId, '_terms_product_cat'));
    }

    public function test_existing_simple_product_is_updated_by_sku(): void
    {
        $existing = new \WC_Product_Simple();
        $existing->set_name('Old Name');
        $existing->set_slug('old-name');
        $existing->set_sku('WHEY-001');
        $existing_id = $existing->save();

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::simpleProduct()]);

        $migrator = new ProductsMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $product = wc_get_product($existing_id);

        $this->assertSame('Clean Whey', $product->get_name());
        $this->assertSame(1, (new State())->get()['stats']['products_updated']);
    }

    public function test_variable_product_and_variations_are_created(): void
    {
        (new State())->save([
            'category_map' => [10 => 77],
            'product_map'  => [],
            'variation_map'=> [],
            'customer_map' => [],
            'order_map'    => [],
            'stats'        => [],
        ]);

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturnCallback(
            static function (string $endpoint): array {
                if ($endpoint === 'products') {
                    return [ApiFixtures::variableProduct()];
                }

                if ($endpoint === 'products/201/variations') {
                    return ApiFixtures::variableProductVariations();
                }

                return [];
            }
        );

        $migrator = new ProductsMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $state    = (new State())->get();
        $parentId = $state['product_map'][201];
        $parent   = wc_get_product($parentId);

        $this->assertInstanceOf(\WC_Product_Variable::class, $parent);
        $this->assertCount(2, $parent->get_children());
        $this->assertCount(2, $state['variation_map']);
        $this->assertSame(2, $state['stats']['variations_created']);
    }

    public function test_unsupported_product_types_are_skipped(): void
    {
        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([[
            'id'   => 999,
            'name' => 'Grouped Thing',
            'type' => 'grouped',
        ]]);

        $migrator = new ProductsMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $this->assertSame([], (new State())->get()['product_map']);
        $this->assertNotEmpty(\WP_CLI::$warnings);
    }
}
