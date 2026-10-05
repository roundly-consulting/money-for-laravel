<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\AssertionFailedError;
use RoundlyConsulting\Money\Contracts\CurrencyConverter;
use RoundlyConsulting\Money\Contracts\ExchangeRateProvider;
use RoundlyConsulting\Money\Contracts\ExchangeRateSource;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Exceptions\InvalidExchangeRate;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyConfiguration;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Exchange\ExchangeRate;
use RoundlyConsulting\Money\Exchange\Providers\EcbExchangeRateProvider;
use RoundlyConsulting\Money\Facades\Exchange;
use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\MoneyBag;
use RoundlyConsulting\Money\Ratio;
use RoundlyConsulting\Money\Testing\FakeRateStore;

it('is seen by Money::convertTo even after a real converter was resolved', function (): void {
    app(CurrencyConverter::class);

    $fake = Exchange::fake(['EUR/USD' => '1.0854']);

    expect(Money::ofMinor(1000, 'EUR')->convertTo('USD')->minor())->toBe('1085')
        ->and(Exchange::convert(Money::ofMinor(1000, 'USD'), 'EUR')->minor())->toBe('921')
        ->and(Exchange::convertWithRate(Money::ofMinor(1000, 'EUR'), 'USD')->rate->source)->toBe('fake')
        ->and(Exchange::driver())->toBe($fake)
        ->and(Exchange::provider('ecb'))->toBe($fake);

    $fake->assertRateRequested('eur', 'usd');
});

it('asserts what was and was not requested', function (): void {
    $fake = Exchange::fake(['EUR/USD' => '1.1']);

    $fake->assertNothingRequested();

    expect(fn () => $fake->assertRateRequested('EUR', 'USD'))->toThrow(AssertionFailedError::class);

    $fake->rate(Currency::of('EUR'), Currency::of('USD'));

    expect(fn () => $fake->assertNothingRequested())->toThrow(AssertionFailedError::class);
});

it('totals a money bag with one rounding over the exact sum', function (): void {
    // Each leg alone is x.5 minor units: rounding per currency would give 1 + 1 + 1 = 3 extra
    // units under HalfAwayFromZero; the exact sum is 1.5 → 2 under HalfEven.
    Exchange::fake(['USD/EUR' => '0.5', 'GBP/EUR' => '0.5', 'CHF/EUR' => '0.5']);

    $bag = MoneyBag::of(Money::ofMinor(1, 'USD'), Money::ofMinor(1, 'GBP'), Money::ofMinor(1, 'CHF'), Money::ofMinor(100, 'EUR'));

    expect($bag->total('EUR')->minor())->toBe('102')
        ->and($bag->total('EUR', rounding: RoundingMode::HalfAwayFromZero)->minor())->toBe('102')
        ->and(MoneyBag::of(Money::ofMinor(1, 'USD'))->total('EUR')->minor())->toBe('0')
        ->and(MoneyBag::of()->total('EUR')->isZero())->toBeTrue()
        ->and(MoneyBag::of(Money::ofMinor(1, 'USD'), Money::ofMinor(1, 'GBP'), Money::ofMinor(1, 'CHF'))->total(Currency::of('EUR'))->minor())->toBe('2');
});

it('is a manager subtype that injected managers receive', function (): void {
    $fake = Exchange::fake(['EUR/USD' => '1.1']);

    expect($fake)->toBeInstanceOf(ExchangeManager::class)
        ->and(app(ExchangeManager::class))->toBe($fake)
        ->and(app(ExchangeRateProvider::class))->toBe($fake)
        ->and($fake->getContainer())->toBe(app())
        ->and(Exchange::getDefaultDriver())->toBe('ecb');
});

// Regression: the fake used to lack source() and extend(), so the documented calls threw
// BadMethodCallException as soon as a test faked the facade.
it('keeps source() and extend() working under the fake', function (): void {
    Exchange::fake(['EUR/USD' => '1.1']);

    $source = new class implements ExchangeRateSource
    {
        public function name(): string
        {
            return 'static';
        }

        public function fetch(CarbonInterface $from, CarbonInterface $to): iterable
        {
            return [];
        }
    };

    expect(Exchange::extend('static', fn (): ExchangeRateSource => $source))->toBeInstanceOf(ExchangeManager::class)
        ->and(Exchange::source('ecb'))->toBeInstanceOf(EcbExchangeRateProvider::class)
        ->and(Exchange::source('ecb')->name())->toBe('ecb')
        ->and(Exchange::source('static'))->toBe($source)
        ->and(Exchange::driver('static')->rate(Currency::of('EUR'), Currency::of('USD'))->decimal(1))->toBe('1.1');
});

it('fails loudly on an unknown method instead of recursing into itself', function (): void {
    Exchange::fake();

    Exchange::nope();
})->throws(BadMethodCallException::class, 'FakeExchangeRates::nope does not exist');

it('records rates() calls instead of running them', function (): void {
    Queue::fake();
    $fake = Exchange::fake();
    $date = CarbonImmutable::parse('2026-09-24');

    $refreshed = Exchange::rates()->refresh('ecb', EcbFeed::Recent, $date, now());
    Exchange::rates()->refreshLater('ecb', EcbFeed::History);
    $stored = Exchange::rates()->store(
        ExchangeRate::fromDecimal('EUR', 'USD', '1.1403', $date, 'ecb'),
        ExchangeRate::fromDecimal('EUR', 'USD', '1.1301', $date->subDay(), 'ecb'),
    );
    $manual = Exchange::rates()->manual('eur', 'czk', '25.10', $date);
    $pruned = Exchange::rates()->prune($date->subYear(), includeManual: true);

    expect($refreshed->source)->toBe('ecb')
        ->and($refreshed->stored)->toBe(0)
        ->and($stored->stored)->toBe(2)
        ->and($stored->source)->toBe('ecb')
        ->and($stored->latestDate?->toDateString())->toBe('2026-09-24')
        ->and($manual->source)->toBe('manual')
        ->and(Exchange::rates()->store()->source)->toBe('manual')
        ->and($pruned)->toBe(0)
        ->and(Exchange::rates())->toBe($fake->rates())
        ->and(CurrencyRate::query()->count())->toBe(0);

    Queue::assertNothingPushed();

    $fake->assertRefreshed();
    $fake->assertRefreshed('ecb', EcbFeed::Recent);
    $fake->assertRefreshQueued('ecb', EcbFeed::History);
    $fake->assertStored('EUR', 'USD');
    $fake->assertStored(Currency::of('EUR'), 'usd', '1.1403', 'ecb');
    $fake->assertStored('EUR', 'CZK', Ratio::of(251, 10), 'manual');
    $fake->assertPruned();
    $fake->assertPruned($date->subYear());
    Exchange::rates()->assertStored('EUR', 'CZK');
});

it('fails each rates() assertion when the call did not happen', function (): void {
    $fake = Exchange::fake();

    $fake->assertNothingRefreshed();
    $fake->assertNothingQueued();
    $fake->assertNothingStored();
    $fake->assertNothingPruned();

    expect(fn () => $fake->assertRefreshed())->toThrow(AssertionFailedError::class, 'No exchange-rate refresh was refreshed.')
        ->and(fn () => $fake->assertRefreshQueued())->toThrow(AssertionFailedError::class, 'was queued')
        ->and(fn () => $fake->assertStored('EUR', 'USD'))->toThrow(AssertionFailedError::class, '[EUR/USD]')
        ->and(fn () => $fake->assertPruned())->toThrow(AssertionFailedError::class, 'were not pruned');

    Exchange::rates()->refresh('ecb', EcbFeed::Daily);
    Exchange::rates()->refreshLater('ecb');
    Exchange::rates()->manual('EUR', 'USD', '1.2', CarbonImmutable::parse('2026-09-24'));
    Exchange::rates()->prune(CarbonImmutable::parse('2026-01-01'));

    expect(fn () => $fake->assertNothingRefreshed())->toThrow(AssertionFailedError::class, '1 exchange-rate refresh(es) ran.')
        ->and(fn () => $fake->assertNothingQueued())->toThrow(AssertionFailedError::class, 'were queued')
        ->and(fn () => $fake->assertNothingStored())->toThrow(AssertionFailedError::class, '1 exchange rate(s) were stored.')
        ->and(fn () => $fake->assertNothingPruned())->toThrow(AssertionFailedError::class, 'prune(s) ran')
        ->and(fn () => $fake->assertRefreshed('fixer'))->toThrow(AssertionFailedError::class, 'from [fixer]')
        ->and(fn () => $fake->assertRefreshed('ecb', EcbFeed::History))->toThrow(AssertionFailedError::class, 'with the [history] feed')
        ->and(fn () => $fake->assertRefreshQueued('ecb', EcbFeed::Recent))->toThrow(AssertionFailedError::class, 'was queued')
        ->and(fn () => $fake->assertStored('EUR', 'USD', '1.3'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertStored('EUR', 'USD', source: 'ecb'))->toThrow(AssertionFailedError::class)
        ->and(fn () => $fake->assertPruned(CarbonImmutable::parse('2026-02-01')))->toThrow(AssertionFailedError::class, 'before [2026-02-01]');
});

it('records the artisan commands too, since they go through the manager', function (): void {
    $fake = Exchange::fake();

    $this->artisan('money:rates:refresh', ['--feed' => 'recent'])->assertSuccessful();
    $this->artisan('money:rates:refresh', ['--queue' => true])->assertSuccessful();
    $this->artisan('money:rates:prune', ['--before' => '2026-01-01', '--pretend' => true])->assertSuccessful();

    $fake->assertRefreshed('ecb', EcbFeed::Recent);
    $fake->assertRefreshQueued('ecb', EcbFeed::Daily);
    $fake->assertPruned(CarbonImmutable::parse('2026-01-01'));
});

// The fake must refuse what the real write path refuses, or a test passes on a rate that
// throws in production.
it('refuses rates that cannot be stored exactly, like the real store', function (): void {
    $fake = Exchange::fake();
    $date = CarbonImmutable::parse('2026-09-24');
    $tooLong = '12345678901234567890.12345678901234567891';

    expect(strlen($tooLong))->toBe(41)
        ->and(fn () => Exchange::rates()->manual('EUR', 'USD', Ratio::of(1, 3)))->toThrow(InvalidExchangeRate::class, 'exact decimal')
        ->and(fn () => Exchange::rates()->store(
            ExchangeRate::fromDecimal('EUR', 'CZK', '25.10', $date, 'ecb'),
            ExchangeRate::fromDecimal('EUR', 'USD', $tooLong, $date, 'ecb'),
        ))->toThrow(InvalidExchangeRate::class, 'exact decimal');

    $fake->assertNothingStored();
});

it('refuses a refresh from a driver the real refresh cannot fetch from', function (string $source): void {
    $fake = Exchange::fake();

    expect(fn () => Exchange::rates()->refresh($source))->toThrow(InvalidMoneyConfiguration::class, "[{$source}] cannot be fetched from");

    $fake->assertNothingRefreshed();
})->with(['database', 'config', 'chain', 'nope']);

it('still records a refresh from ecb and from an extended source', function (): void {
    $fake = Exchange::fake();

    Exchange::extend('static', fn (): ExchangeRateSource => new class implements ExchangeRateSource
    {
        public function name(): string
        {
            return 'static';
        }

        public function fetch(CarbonInterface $from, CarbonInterface $to): iterable
        {
            throw new LogicException('the fake must never fetch');
        }
    });

    Exchange::rates()->refresh('ecb');
    Exchange::rates()->refresh('static');

    $fake->assertRefreshed('ecb');
    $fake->assertRefreshed('static');
});

it('validates the source on a fake store built without the manager', function (): void {
    Exchange::fake();

    (new FakeRateStore(app()))->refresh('database');
})->throws(InvalidMoneyConfiguration::class, '[database] cannot be fetched from');
