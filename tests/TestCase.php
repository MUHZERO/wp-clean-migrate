<?php

declare(strict_types=1);

namespace MuhCleanMigrator\Tests;

use MuhCleanMigrator\Tests\Support\FakeWpEnvironment;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        FakeWpEnvironment::reset();
        $this->resetPluginBootstrap();
    }

    protected function tearDown(): void
    {
        FakeWpEnvironment::reset();

        parent::tearDown();
    }

    protected function resetPluginBootstrap(): void
    {
        $reflection = new \ReflectionClass(\MuhCleanMigrator\Plugin::class);
        $property   = $reflection->getProperty('initialized');
        $property->setAccessible(true);
        $property->setValue(null, false);
    }
}
