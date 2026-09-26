<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use RoundlyConsulting\Money\Commands\ListCurrenciesCommand;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currencies\DefaultCurrencyRegistry;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class MoneyServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('money')
            ->hasConfigFile()
            ->hasCommands([
                ListCurrenciesCommand::class,
            ])
            ->contributesToAbout(fn (): array => $this->about());
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(CurrencyRegistry::class, static fn (): CurrencyRegistry => DefaultCurrencyRegistry::fromConfig());
    }

    /**
     * Driver names and counts only — never provider config, which may hold credentials.
     *
     * @return array<string, string>
     */
    private function about(): array
    {
        $registry = $this->app->make(CurrencyRegistry::class);

        return [
            'Default currency' => (string) config('money.default_currency'),
            'Currencies' => sprintf('ISO %d / custom %d', count($registry->iso()), count($registry->custom())),
        ];
    }
}
