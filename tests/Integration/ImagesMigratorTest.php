<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Integration;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\ProductImageImporter;
use MuhCleanMigrator\Helpers\Sanitizer;
use MuhCleanMigrator\Migrators\ImagesMigrator;
use MuhCleanMigrator\Tests\Fixtures\ApiFixtures;
use MuhCleanMigrator\Tests\Support\FakeWpEnvironment;
use MuhCleanMigrator\Tests\TestCase;

final class ImagesMigratorTest extends TestCase
{
    public function test_products_without_mapping_are_skipped(): void
    {
        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::simpleProduct()]);

        $migrator = new ImagesMigrator($client, new State(), new Logger(), new Sanitizer(), new ProductImageImporter(new Logger()));
        $migrator->sync([]);

        $this->assertSame(1, (new State())->get()['stats']['images_skipped']);
    }

    public function test_featured_and_gallery_images_are_imported_without_duplication_on_rerun(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name('Clean Whey');
        $product->set_slug('clean-whey');
        $product_id = $product->save();
        update_post_meta($product_id, '_muh_old_product_id', '101');

        (new State())->save([
            'category_map' => [],
            'product_map'  => [101 => $product_id],
            'variation_map'=> [],
            'customer_map' => [],
            'order_map'    => [],
            'stats'        => [],
        ]);

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::simpleProduct()]);

        $migrator = new ImagesMigrator($client, new State(), new Logger(), new Sanitizer(), new ProductImageImporter(new Logger()));
        $migrator->sync([]);
        $migrator->sync([]);

        $gallery = explode(',', (string) get_post_meta($product_id, '_product_image_gallery', true));

        $this->assertNotSame(0, get_post_thumbnail_id($product_id));
        $this->assertCount(1, array_filter($gallery));
        $this->assertCount(2, FakeWpEnvironment::getPostsByQuery(['post_type' => 'attachment']));
    }

    public function test_invalid_image_urls_are_rejected_safely(): void
    {
        $product = new \WC_Product_Simple();
        $product_id = $product->save();
        update_post_meta($product_id, '_muh_old_product_id', '101');

        (new State())->save([
            'category_map' => [],
            'product_map'  => [101 => $product_id],
            'variation_map'=> [],
            'customer_map' => [],
            'order_map'    => [],
            'stats'        => [],
        ]);

        $item = ApiFixtures::simpleProduct();
        $item['images'] = [
            ['src' => 'https://old.example.com/evil.php', 'name' => 'Bad'],
        ];

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([$item]);

        $migrator = new ImagesMigrator($client, new State(), new Logger(), new Sanitizer(), new ProductImageImporter(new Logger()));
        $migrator->sync([]);

        $this->assertSame(1, (new State())->get()['stats']['images_failed']);
    }

    public function test_variable_variation_images_are_imported(): void
    {
        $parent = new \WC_Product_Variable();
        $parent->set_name('Clean Tee');
        $parent->set_slug('clean-tee');
        $parent_id = $parent->save();
        update_post_meta($parent_id, '_muh_old_product_id', '201');

        $variation = new \WC_Product_Variation();
        $variation->set_parent_id($parent_id);
        $variation_id = $variation->save();
        update_post_meta($variation_id, '_muh_old_variation_id', '301');

        (new State())->save([
            'category_map' => [],
            'product_map'  => [201 => $parent_id],
            'variation_map'=> [301 => $variation_id],
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
                    return [ApiFixtures::variableProductVariations()[0]];
                }

                return [];
            }
        );

        $migrator = new ImagesMigrator($client, new State(), new Logger(), new Sanitizer(), new ProductImageImporter(new Logger()));
        $migrator->sync([]);

        $savedVariation = wc_get_product($variation_id);
        $this->assertSame(3, (new State())->get()['stats']['images_downloaded']);
        $this->assertNotSame(0, $savedVariation->get_image_id());
    }
}
