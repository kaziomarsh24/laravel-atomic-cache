<?php

namespace KaziOmar\AtomicCache\Tests;

use KaziOmar\AtomicCache\AtomicCacheServiceProvider;
use KaziOmar\AtomicCache\Facades\AtomicCache;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            AtomicCacheServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'AtomicCache' => AtomicCache::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('atomic-cache.enabled', true);
    }
}
