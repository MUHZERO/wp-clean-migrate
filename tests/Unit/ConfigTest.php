<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Unit;

use MuhCleanMigrator\Core\Config;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function test_missing_config_logs_error_and_throws(): void
    {
        $config = new Config(new Logger(), [
            'old_url'    => '',
            'old_key'    => '',
            'old_secret' => '',
        ]);

        $this->expectException(\RuntimeException::class);

        try {
            $config->get();
        } finally {
            $this->assertNotEmpty(\WP_CLI::$errors);
            $this->assertStringContainsString('Missing config', \WP_CLI::$errors[0]);
        }
    }

    public function test_valid_config_is_returned(): void
    {
        $config = new Config(new Logger(), [
            'old_url'    => 'https://old.example.com',
            'old_key'    => 'ck_test',
            'old_secret' => 'cs_test',
        ]);

        $this->assertSame([
            'old_url'    => 'https://old.example.com',
            'old_key'    => 'ck_test',
            'old_secret' => 'cs_test',
        ], $config->get());
    }
}
