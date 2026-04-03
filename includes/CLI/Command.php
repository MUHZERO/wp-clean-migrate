<?php

declare(strict_types=1);

namespace MuhCleanMigrator\CLI;

use MuhCleanMigrator\API\WooClient;
use MuhCleanMigrator\API\WordPressClient;
use MuhCleanMigrator\Core\Config;
use MuhCleanMigrator\Core\Logger;
use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Helpers\ProductImageImporter;
use MuhCleanMigrator\Helpers\Sanitizer;
use MuhCleanMigrator\Migrators\CategoriesMigrator;
use MuhCleanMigrator\Migrators\CustomersMigrator;
use MuhCleanMigrator\Migrators\ImagesMigrator;
use MuhCleanMigrator\Migrators\MenusMigrator;
use MuhCleanMigrator\Migrators\OrdersMigrator;
use MuhCleanMigrator\Migrators\ProductsMigrator;

/**
 * Registers and dispatches the `wp muh-migrate` command.
 */
final class Command
{
    private Config $config;

    private State $state;

    private Logger $logger;

    private Sanitizer $sanitizer;

    /**
     * @var array<string, callable>
     */
    private array $action_handlers;

    /**
     * @param array<string, callable> $action_handlers Optional action overrides for tests.
     */
    public function __construct(
        Config $config,
        State $state,
        Logger $logger,
        Sanitizer $sanitizer,
        array $action_handlers = []
    )
    {
        $this->config          = $config;
        $this->state           = $state;
        $this->logger          = $logger;
        $this->sanitizer       = $sanitizer;
        $this->action_handlers = $action_handlers;
    }

    /**
     * Handles CLI requests.
     *
     * ## OPTIONS
     *
     * <action>
     * : One of categories, products, orders, customers, images, menus, status, reset.
     *
     * [--page=<page>]
     * : Page number for paginated source requests.
     *
     * [--per-page=<per-page>]
     * : Batch size for paginated source requests.
     *
     * [--dry-run=<dry-run>]
     * : When truthy, log planned actions without writing changes.
     *
     * @param array<int, string>       $args       Positional arguments.
     * @param array<string, mixed>     $assoc_args Associative CLI arguments.
     */
    public function __invoke(array $args, array $assoc_args): void
    {
        $action = $args[0] ?? null;

        if (!$action) {
            $this->logger->error(
                'Usage: wp muh-migrate <categories|products|orders|customers|images|menus|status|reset> [--page=1] [--per-page=20] [--dry-run=1]'
            );
            return;
        }

        if (!class_exists('WooCommerce')) {
            $this->logger->error('WooCommerce must be active on the new site.');
            return;
        }

        try {
            switch ($action) {
                case 'categories':
                    $this->dispatchAction('categories', $assoc_args, function (array $args): void {
                        $this->makeCategoriesMigrator()->sync($args);
                    });
                    break;

                case 'products':
                    $this->dispatchAction('products', $assoc_args, function (array $args): void {
                        $this->makeProductsMigrator()->sync($args);
                    });
                    break;

                case 'orders':
                    $this->dispatchAction('orders', $assoc_args, function (array $args): void {
                        $this->makeOrdersMigrator()->sync($args);
                    });
                    break;

                case 'customers':
                    $this->dispatchAction('customers', $assoc_args, function (array $args): void {
                        $this->makeCustomersMigrator()->sync($args);
                    });
                    break;

                case 'images':
                    $this->dispatchAction('images', $assoc_args, function (array $args): void {
                        $this->makeImagesMigrator()->sync($args);
                    });
                    break;

                case 'menus':
                    $this->dispatchAction('menus', $assoc_args, function (array $args): void {
                        $this->makeMenusMigrator()->sync($args);
                    });
                    break;

                case 'status':
                    $this->showStatus();
                    break;

                case 'reset':
                    $this->resetState();
                    break;

                default:
                    $this->logger->error("Unknown action: {$action}");
            }
        } catch (\RuntimeException $exception) {
            return;
        }
    }

    /**
     * @param array<string, mixed> $assoc_args CLI args.
     */
    private function dispatchAction(string $action, array $assoc_args, callable $fallback): void
    {
        if (isset($this->action_handlers[$action])) {
            call_user_func($this->action_handlers[$action], $assoc_args);
            return;
        }

        $fallback($assoc_args);
    }

    /**
     * Displays migration status from stored plugin state.
     */
    private function showStatus(): void
    {
        $state = $this->state->get();

        $this->logger->log('Current migration status:');
        $this->logger->log(wp_json_encode($state['stats'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->logger->log('Mapped categories: ' . count($state['category_map']));
        $this->logger->log('Mapped products: ' . count($state['product_map']));
        $this->logger->log('Mapped variations: ' . count($state['variation_map']));
        $this->logger->log('Mapped orders: ' . count($state['order_map']));
        $this->logger->log('Mapped customers: ' . count($state['customer_map']));
    }

    /**
     * Clears stored migration state.
     */
    private function resetState(): void
    {
        $this->state->reset();
        $this->logger->log('State reset done.');
    }

    private function makeWooClient(): WooClient
    {
        return new WooClient($this->config->get(), $this->logger);
    }

    private function makeWordPressClient(): WordPressClient
    {
        return new WordPressClient($this->config->get(), $this->logger);
    }

    private function makeCategoriesMigrator(): CategoriesMigrator
    {
        return new CategoriesMigrator($this->makeWooClient(), $this->state, $this->logger, $this->sanitizer);
    }

    private function makeProductsMigrator(): ProductsMigrator
    {
        return new ProductsMigrator($this->makeWooClient(), $this->state, $this->logger, $this->sanitizer);
    }

    private function makeCustomersMigrator(): CustomersMigrator
    {
        return new CustomersMigrator($this->makeWooClient(), $this->state, $this->logger, $this->sanitizer);
    }

    private function makeOrdersMigrator(): OrdersMigrator
    {
        return new OrdersMigrator($this->makeWooClient(), $this->state, $this->logger, $this->sanitizer);
    }

    private function makeImagesMigrator(): ImagesMigrator
    {
        return new ImagesMigrator(
            $this->makeWooClient(),
            $this->state,
            $this->logger,
            $this->sanitizer,
            new ProductImageImporter($this->logger)
        );
    }

    private function makeMenusMigrator(): MenusMigrator
    {
        return new MenusMigrator(
            $this->makeWordPressClient(),
            $this->state,
            $this->logger,
            $this->sanitizer
        );
    }
}
