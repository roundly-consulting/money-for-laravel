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

    /** @return list<string> */
    protected function migrationSources(): array
    {
        return [__DIR__.'/Fixtures/migrations'];
    }
}
