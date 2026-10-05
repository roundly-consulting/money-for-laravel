<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/money-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=money-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/money-for-laravel/main/art/hero.png" alt="Money for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/money-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/money-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/money-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/money-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/money-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/money-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=money-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Money for Laravel

Immutable, arbitrary-precision `Money` and `Currency` value objects for Laravel: exact decimal
parsing, rounding-safe arithmetic and allocation, locale formatting, Eloquent casts, validation,
discounts, tax and pluggable exchange rates. Amounts are minor units held as integer strings with
bcmath arithmetic, so there is no float anywhere and no int64 ceiling.

## Installation

Requires PHP 8.4 (`ext-bcmath`; `ext-intl` for locale-aware formatting) and Laravel 12 or 13.

```bash
composer require roundly-consulting/money-for-laravel
```

## Usage

Calculate with exact amounts. Every lossy step rounds once, and currencies never mix silently:

```php
use RoundlyConsulting\Money\Money;

$price = Money::ofMajor('19.99', 'EUR');                          // minor "1999", never a float
$total = $price->add(Money::ofMinor(250, 'EUR'))->multiply(3);    // 67.47 EUR, exact
[$a, $b, $c] = $total->allocate(1, 1, 1);                         // parts always sum back to $total
$total->add(Money::ofMajor('1', 'USD'));                          // throws CurrencyMismatch

$total->toDecimal();       // "67.47"
$total->format('sk');      // "67,47 €"
```

Store it on a model: `$table->money('price')` creates the amount and currency columns:

```php
use RoundlyConsulting\Money\Casts\AsMoney;

Schema::create('products', function (Blueprint $table) {
    $table->id();
    $table->money('price');                       // decimal(38,0) price + price_currency
    $table->timestamps();
});

final class Product extends Model
{
    protected $fillable = ['price'];

    protected function casts(): array
    {
        return ['price' => AsMoney::class];
    }
}

$product = Product::create(['price' => Money::ofMajor('19.99', 'EUR')]);
```

Convert with the `Exchange` facade (ECB rates by default, no setup):

```php
use RoundlyConsulting\Money\Facades\Exchange;

$product->price->convertTo('USD');                    // Money in USD at today's rate
Exchange::convertWithRate($product->price, 'CZK');    // Conversion: original, converted, rate
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/money-for-laravel](https://roundly-consulting.com/open-source/docs/money-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=money-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=money-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=money-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
