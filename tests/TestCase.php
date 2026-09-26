<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Tests;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Money\MoneyServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function packageProviders(): array
    {
        return [MoneyServiceProvider::class];
    }

    /** @return list<class-string<ServiceProvider>|string> */
    protected function migrationSources(): array
    {
        return [MoneyServiceProvider::class, __DIR__.'/Fixtures/migrations'];
    }

    /**
     * A fake provider credential, so the about section is proven never to render provider
     * config.
     *
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            'cache.default' => 'array',
            'money.exchange.providers.custom.key' => 'sk_test_money_dummy_7f3a',
        ];
    }
}
