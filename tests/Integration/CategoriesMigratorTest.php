<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Integration;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;
use MuhCleanMigrator\Migrators\CategoriesMigrator;
use MuhCleanMigrator\Tests\Fixtures\ApiFixtures;
use MuhCleanMigrator\Tests\Support\FakeWpEnvironment;
use MuhCleanMigrator\Tests\TestCase;

final class CategoriesMigratorTest extends TestCase
{
    public function test_creates_category_and_updates_state_map(): void
    {
        $client = $this->createMock(WooClient::class);
        $client->expects($this->once())
            ->method('get')
            ->with('products/categories', $this->arrayHasKey('page'))
            ->willReturn([ApiFixtures::categories()[0]]);

        $migrator = new CategoriesMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $state = (new State())->get();

        $this->assertSame(1, $state['stats']['categories_created']);
        $this->assertArrayHasKey(10, $state['category_map']);
        $term = FakeWpEnvironment::getTermBySlug('supplements', 'product_cat');
        $this->assertNotNull($term);
    }

    public function test_updates_existing_category_by_slug_and_parent_mapping(): void
    {
        $existing = wp_insert_term('Protein', 'product_cat', [
            'slug'        => 'protein',
            'description' => 'Old',
            'parent'      => 0,
        ]);

        (new State())->save([
            'category_map' => [10 => 99],
            'product_map'  => [],
            'variation_map'=> [],
            'customer_map' => [],
            'order_map'    => [],
            'stats'        => [],
        ]);

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::categories()[1]]);

        $migrator = new CategoriesMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $state = (new State())->get();
        $term  = FakeWpEnvironment::$terms[$existing['term_id']];

        $this->assertSame(1, $state['stats']['categories_updated']);
        $this->assertSame(99, $term['parent']);
        $this->assertSame($existing['term_id'], $state['category_map'][11]);
    }
}
