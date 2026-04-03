<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Unit;

use MuhCleanMigrator\CLI\Command;
use MuhCleanMigrator\Core\Config;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\Sanitizer;
use MuhCleanMigrator\Tests\TestCase;

final class CommandTest extends TestCase
{
    public function test_missing_action_returns_usage_error(): void
    {
        $command = $this->makeCommand();

        $command->__invoke([], []);

        $this->assertNotEmpty(\WP_CLI::$errors);
        $this->assertStringContainsString('Usage: wp muh-migrate', \WP_CLI::$errors[0]);
    }

    public function test_unknown_action_returns_error(): void
    {
        $command = $this->makeCommand();

        $command->__invoke(['unknown'], []);

        $this->assertStringContainsString('Unknown action', \WP_CLI::$errors[0]);
    }

    public function test_known_action_dispatches_to_handler(): void
    {
        $seen = null;

        $command = $this->makeCommand([
            'products' => function (array $assoc_args) use (&$seen): void {
                $seen = $assoc_args;
            },
        ]);

        $command->__invoke(['products'], ['page' => 2, 'per-page' => 10]);

        $this->assertSame(['page' => 2, 'per-page' => 10], $seen);
    }

    public function test_status_outputs_saved_state_counts(): void
    {
        $state = new State();
        $state->save([
            'category_map' => [1 => 101],
            'product_map'  => [2 => 102],
            'variation_map'=> [3 => 103],
            'customer_map' => [4 => 104],
            'order_map'    => [5 => 105],
            'stats'        => ['products_created' => 1],
        ]);

        $command = $this->makeCommand();
        $command->__invoke(['status'], []);

        $output = implode("\n", \WP_CLI::$logs);

        $this->assertStringContainsString('Mapped products: 1', $output);
        $this->assertStringContainsString('Mapped variations: 1', $output);
        $this->assertStringContainsString('Mapped orders: 1', $output);
    }

    /**
     * @param array<string, callable> $handlers
     */
    private function makeCommand(array $handlers = []): Command
    {
        return new Command(
            new Config(new Logger(), [
                'old_url'    => 'https://old.example.com',
                'old_key'    => 'ck',
                'old_secret' => 'cs',
            ]),
            new State(),
            new Logger(),
            new Sanitizer(),
            $handlers
        );
    }
}
