# Changelog

All notable changes to `money-for-laravel` will be documented in this file.

## Unreleased

Initial implementation:

- `Money` over arbitrary-precision minor units (canonical integer strings, bcmath, 65-digit cap),
  `Currency` with the bundled ISO 4217 registry (List One 2026-09-17, CLDR 47 symbols) and
  custom currencies (exponent 0..18), `MoneyBag`, `Ratio`, `Percentage`, `MinorUnits`.
- Exact parsing, allocation, one-rounding arithmetic on PHP 8.4 `\RoundingMode`.
- Intl and deterministic formatters, a localized parser, the `@money` Blade directive.
- `AsMoney` / `AsMoneyJson` / `AsCurrency` casts, `money` / `moneyJson` / `currencyCode` schema
  macros (`decimal(38,0)` amounts), collection and request macros, `MoneyAmount` / `CurrencyCode`
  rules (en + sk messages), `MoneyResource`, a PHPStan extension for the macros.
- Discounts (fixed / percentage / free shipping, caps, stacking strategies, exclusivity,
  allocation) and tax helpers (net ⇄ gross, breakdowns, summaries).
- Exchange rates: `config`, `database`, `ecb` and `chain` drivers, caching, historical dates,
  `Exchange::fake()`, refresh / prune / convert commands, a queued refresh job and an optional
  schedule.
