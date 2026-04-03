<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Unit;

use MuhCleanMigrator\Plugin;
use MuhCleanMigrator\Tests\Support\FakeWpEnvironment;
use MuhCleanMigrator\Tests\TestCase;

final class PluginTest extends TestCase
{
    public function test_init_registers_command_and_disables_emails(): void
    {
        Plugin::init();

        $this->assertArrayHasKey('muh-migrate', \WP_CLI::$commands);
        $this->assertCount(7, FakeWpEnvironment::$filters);
        $this->assertSame('__return_false', FakeWpEnvironment::$filters['woocommerce_email_enabled_new_order']);
    }

    public function test_init_is_idempotent(): void
    {
        Plugin::init();
        Plugin::init();

        $this->assertCount(1, \WP_CLI::$commands);
    }
}
