<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use RoundlyConsulting\Money\Commands\ConvertMoneyCommand;
use RoundlyConsulting\Money\Commands\ListCurrenciesCommand;
use RoundlyConsulting\Money\Commands\PruneExchangeRatesCommand;
use RoundlyConsulting\Money\Commands\RefreshExchangeRatesCommand;
use RoundlyConsulting\Money\Contracts\CurrencyConverter;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Contracts\MoneyParser;
use RoundlyConsulting\Money\Currencies\DefaultCurrencyRegistry;
use RoundlyConsulting\Money\Discounts\DiscountAllocator;
use RoundlyConsulting\Money\Enums\FormatterDriver;
use RoundlyConsulting\Money\Exceptions\FormatterUnavailable;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\Converter;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Formatting\DecimalMoneyFormatter;
use RoundlyConsulting\Money\Formatting\IntlMoneyFormatter;
use RoundlyConsulting\Money\Parsing\LocalizedMoneyParser;
use RoundlyConsulting\Money\Support\BladeMoney;
use RoundlyConsulting\Money\Support\MoneyBlueprint;
use RoundlyConsulting\Money\Support\MoneyMacros;
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
            ->hasTranslations()
            ->hasMigrations()
            ->hasCommands([
                ListCurrenciesCommand::class,
                RefreshExchangeRatesCommand::class,
                PruneExchangeRatesCommand::class,
                ConvertMoneyCommand::class,
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

        $this->app->singleton(DiscountAllocator::class);

        $this->app->singleton(ExchangeManager::class, static fn (Application $app): ExchangeManager => new ExchangeManager($app));
        $this->app->singleton(ExchangeRateProvider::class, static fn (Application $app): ExchangeRateProvider => $app->make(ExchangeManager::class));

        // Bound, not a singleton: a fresh converter picks up Exchange::fake() every time.
        $this->app->bind(CurrencyConverter::class, static fn (Application $app): CurrencyConverter => new Converter($app->make(ExchangeRateProvider::class)));

        $this->app->singleton(MoneyParser::class, static fn (Application $app): MoneyParser => new LocalizedMoneyParser($app->make(CurrencyRegistry::class)));
    }

    public function boot(): void
    {
        parent::boot();

        // Always: published consumer migrations call these macros.
        MoneyBlueprint::register();

        if (config('money.macros.collection') !== false) {
            MoneyMacros::registerCollectionMacros();
        }

        if (config('money.macros.request') !== false) {
            MoneyMacros::registerRequestMacro();
        }

        if (config('money.macros.validation') !== false) {
            $this->callAfterResolving(ValidationFactory::class, static function (ValidationFactory $factory): void {
                MoneyMacros::registerValidationRules($factory);
            });
        }

        if (config('money.macros.blade') !== false) {
            $this->registerBladeDirective('money', BladeMoney::compile(...));
        }

        if (config('money.exchange.refresh.schedule') === true) {
            $this->callAfterResolving(Schedule::class, static function (Schedule $schedule): void {
                $schedule->command('money:rates:refresh', [(string) config('money.exchange.refresh.source')])
                    ->cron((string) config('money.exchange.refresh.cron'))
                    ->timezone((string) config('money.exchange.refresh.timezone'))
                    ->withoutOverlapping()
                    ->onOneServer();
            });
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
            'Exchange driver' => (string) config('money.exchange.default'),
            'Rates schedule' => config('money.exchange.refresh.schedule') === true
                ? (string) config('money.exchange.refresh.cron').' ('.(string) config('money.exchange.refresh.timezone').')'
                : 'off',
        ];
    }
}
