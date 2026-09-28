# Changelog

All notable changes to `money-for-laravel` are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

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
