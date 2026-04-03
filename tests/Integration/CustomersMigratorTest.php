<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Integration;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;
use MuhCleanMigrator\Migrators\CustomersMigrator;
use MuhCleanMigrator\Tests\Fixtures\ApiFixtures;
use MuhCleanMigrator\Tests\Support\FakeWpEnvironment;
use MuhCleanMigrator\Tests\TestCase;

final class CustomersMigratorTest extends TestCase
{
    public function test_valid_customer_creates_user_updates_meta_and_map(): void
    {
        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([ApiFixtures::customer()]);

        $migrator = new CustomersMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $state = (new State())->get();
        $user  = FakeWpEnvironment::getUserBy('email', 'buyer@example.com');

        $this->assertNotNull($user);
        $this->assertSame($user->ID, $state['customer_map'][401]);
        $this->assertSame('Jane', FakeWpEnvironment::$user_meta[$user->ID]['billing_first_name']);
        $this->assertSame(1, $state['stats']['customers_created']);
    }

    public function test_invalid_email_is_skipped(): void
    {
        $customer = ApiFixtures::customer();
        $customer['email'] = 'not-an-email';

        $client = $this->createMock(WooClient::class);
        $client->method('get')->willReturn([$customer]);

        $migrator = new CustomersMigrator($client, new State(), new Logger(), new Sanitizer());
        $migrator->sync([]);

        $this->assertNull(FakeWpEnvironment::getUserBy('email', 'not-an-email'));
        $this->assertNotEmpty(\WP_CLI::$warnings);
    }
}
