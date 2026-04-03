<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests\Unit;

use MuhCleanMigrator\Core\State;
use MuhCleanMigrator\Tests\TestCase;

final class StateTest extends TestCase
{
    public function test_default_state_shape_and_stats_are_populated(): void
    {
        $state = (new State())->get();

        $this->assertArrayHasKey('category_map', $state);
        $this->assertArrayHasKey('product_map', $state);
        $this->assertArrayHasKey('variation_map', $state);
        $this->assertArrayHasKey('customer_map', $state);
        $this->assertArrayHasKey('order_map', $state);
        $this->assertArrayHasKey('stats', $state);
        $this->assertSame(0, $state['stats']['products_created']);
        $this->assertSame(0, $state['stats']['variations_created']);
    }

    public function test_save_and_reset_state_work(): void
    {
        $service = new State();
        $payload = [
            'category_map' => [1 => 10],
            'product_map'  => [2 => 20],
            'variation_map'=> [3 => 30],
            'customer_map' => [],
            'order_map'    => [],
            'stats'        => ['products_created' => 1],
        ];

        $service->save($payload);

        $this->assertSame($payload['product_map'], $service->get()['product_map']);

        $service->reset();

        $this->assertSame([], $service->get()['product_map']);
    }
}
