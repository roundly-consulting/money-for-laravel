# Changelog

All notable changes to `money-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

## 1.0.1 - 2026-10-05

### Changed

- `Exchange::fake()` now triangulates through the configured `money.exchange.pivot` when no pivot
  is passed, like the real drivers; it used `EUR` regardless of config. To keep the old behaviour,
  pass it explicitly: `Exchange::fake($rates, 'EUR')`. `null` still means no triangulation.
- Documentation: the README model example declares `$fillable`, so its `Product::create()` call
  no longer throws `MassAssignmentException`.
- Documentation: supported databases are PostgreSQL and MySQL; SQL Server is not supported.
- Documentation: the README hero image uses an absolute URL, so it renders on Packagist and other
  sites.
- Maintenance: `composer.json` `homepage` and `support.docs` link to the package documentation.

### Fixed

- `AsMoney` casts no longer cache the `Money` object. Reading an attribute before changing its
  currency column, currency attribute or configured currency no longer reverts that change on
  `save()` or throws `CurrencyMismatch` from `save()` / `toArray()`.
- Under `Exchange::fake()`, `Exchange::rates()->store()` and `manual()` refuse a rate with no exact
  decimal of at most 40 characters (e.g. `1/3`) with `InvalidExchangeRate`, as the real store does.
- Under `Exchange::fake()`, `Exchange::rates()->refresh()` refuses a driver that cannot be fetched
  from (`database`, `config`, `chain`, an unknown name) with `InvalidMoneyConfiguration`, as the
  real refresh does.

## 1.0.0 - 2026-10-03

Initial public release.

### Added

- Immutable `Money` and `Currency` value objects over arbitrary-precision minor units — bcmath
  arithmetic, no floats and no 64-bit ceiling.
- The bundled ISO 4217 currency registry plus your own custom currencies.
- Exact decimal parsing, allocation and comparisons; every lossy operation rounds exactly once
  with PHP's native `RoundingMode`.
- Locale-aware and deterministic formatting, a localized parser and a `@money` Blade directive.
- Eloquent casts (`AsMoney`, `AsMoneyJson`, `AsCurrency`) and `money` / `moneyJson` /
  `currencyCode` schema macros.
- `MoneyAmount` and `CurrencyCode` validation rules, request and collection macros, and a
  `MoneyResource` for APIs.
- `MoneyBag` for multi-currency totals, plus `Ratio` and `Percentage` helpers.
- Discounts (fixed, percentage, free shipping) with caps, stacking and allocation, and tax helpers
  for net ⇄ gross and breakdowns.
- Exchange rates from config, the database or the European Central Bank, with caching, historical
  dates and a chain driver.
- Artisan commands `money:currencies`, `money:rates:refresh`, `money:rates:prune` and
  `money:convert`, plus refresh events.
- `Exchange::rates()` for the stored rates table: `refresh()`, `refreshLater()`, `store()`,
  `manual()` and `prune()`, the same code the commands and the queued job run.
- `Exchange::fake()` with `assertRateRequested()` for testing conversions, and
  `assertRefreshed()`, `assertRefreshQueued()`, `assertStored()` and `assertPruned()` (each with
  an `assertNothing…()` twin) for the rates table. The fake is an `ExchangeManager`, so injected
  managers get it too, and `source()` / `extend()` work under it.

### Changed

- `ExchangeManager`'s `create*Driver()` methods are now `protected`; use `Exchange::source()`
  for the raw driver.
