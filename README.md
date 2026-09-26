# money-for-laravel

Immutable, arbitrary-precision `Money` and `Currency` value objects for Laravel: ISO 4217 and
custom currencies, exact decimal parsing, rounding-safe arithmetic and allocation, locale
formatting, Eloquent casts and schema macros, validation, discounts, tax, and pluggable exchange
rates.

## Requirements

- PHP ^8.4 with `ext-bcmath`
- Laravel 12.x or 13.x
- Optional: `ext-intl` (locale-aware formatting), `ext-simplexml` (ECB exchange rates)

## Installation

```bash
composer require roundly-consulting/money-for-laravel
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="money-config"
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
