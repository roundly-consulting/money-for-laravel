<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use Illuminate\Contracts\Foundation\Application;
use RoundlyConsulting\Money\Commands\ListCurrenciesCommand;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Contracts\MoneyParser;
use RoundlyConsulting\Money\Currencies\DefaultCurrencyRegistry;
use RoundlyConsulting\Money\Enums\FormatterDriver;
use RoundlyConsulting\Money\Exceptions\FormatterUnavailable;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Formatting\DecimalMoneyFormatter;
use RoundlyConsulting\Money\Formatting\IntlMoneyFormatter;
use RoundlyConsulting\Money\Parsing\LocalizedMoneyParser;
use RoundlyConsulting\Money\Support\BladeMoney;
use RoundlyConsulting\PackageToolkit\Concerns\RegistersBladeDirectives;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

final class MoneyServiceProvider extends PackageServiceProvider
{
    use RegistersBladeDirectives;

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

        $this->app->singleton(MoneyFormatter::class, static fn (): MoneyFormatter => match (self::formatterDriver()) {
            FormatterDriver::Intl => new IntlMoneyFormatter,
            default => new DecimalMoneyFormatter,
        });

        $this->app->singleton(MoneyParser::class, static fn (Application $app): MoneyParser => new LocalizedMoneyParser($app->make(CurrencyRegistry::class)));
    }

    public function boot(): void
    {
        parent::boot();

        if (config('money.macros.blade') !== false) {
            $this->registerBladeDirective('money', BladeMoney::compile(...));
        }
    }

    /**
     * The formatter the configured driver resolves to: `auto` picks intl when the extension
     * is loaded; an explicit `intl` without it fails loud.
     */
    public static function formatterDriver(?bool $intlLoaded = null): FormatterDriver
    {
        $intlLoaded ??= extension_loaded('intl');
        $driver = Config::using(InvalidMoneyConfiguration::class)->enum('money.formatting.driver', FormatterDriver::class);

        return match ($driver) {
            FormatterDriver::Auto => $intlLoaded ? FormatterDriver::Intl : FormatterDriver::Decimal,
            FormatterDriver::Intl => $intlLoaded ? FormatterDriver::Intl : throw FormatterUnavailable::intl(),
            FormatterDriver::Decimal => FormatterDriver::Decimal,
        };
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
            'Formatter' => self::formatterDriver()->value,
        ];
    }
}
