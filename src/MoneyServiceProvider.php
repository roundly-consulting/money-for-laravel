<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class MoneyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('money')
            ->hasConfigFile()
            ->contributesToAbout(static fn (): array => [
                'Default currency' => (string) config('money.default_currency'),
            ]);
    }
}
