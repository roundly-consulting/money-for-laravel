<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Illuminate\Translation\PotentiallyTranslatedString;
use Illuminate\Validation\Validator;
use RoundingMode;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Math\DecimalString;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Rules\CurrencyCode;
use RoundlyConsulting\Money\Rules\MoneyAmount;

/**
 * Registers the collection, request and validation macros, each behind its
 * `money.macros.*` toggle and a `hasMacro()` guard (an existing host macro wins).
 *
 * @internal
 */
final class MoneyMacros
{
    public const array COLLECTION_MACROS = ['sumMoney', 'minMoney', 'maxMoney', 'avgMoney'];

    public static function registerCollectionMacros(): void
    {
        foreach ([Collection::class, LazyCollection::class] as $class) {
            if (! $class::hasMacro('sumMoney')) {
                $class::macro('sumMoney', function (callable|string|null $value = null, Currency|string|null $currencyIfEmpty = null): Money {
                    return Money::sum(MoneyMacros::values($this, $value), $currencyIfEmpty);
                });
            }

            if (! $class::hasMacro('minMoney')) {
                $class::macro('minMoney', function (callable|string|null $value = null): Money {
                    return Money::min(MoneyMacros::values($this, $value));
                });
            }

            if (! $class::hasMacro('maxMoney')) {
                $class::macro('maxMoney', function (callable|string|null $value = null): Money {
                    return Money::max(MoneyMacros::values($this, $value));
                });
            }

            if (! $class::hasMacro('avgMoney')) {
                $class::macro('avgMoney', function (callable|string|null $value = null, ?RoundingMode $rounding = null): Money {
                    return Money::average(MoneyMacros::values($this, $value), $rounding ?? RoundingModes::fromConfig('money.rounding'));
                });
            }
        }
    }

    public static function registerRequestMacro(): void
    {
        if (Request::hasMacro('money')) {
            return;
        }

        Request::macro('money', function (string $key, Currency|string|null $currency = null, ?string $currencyKey = null): ?Money {
            return MoneyMacros::requestMoney($this, $key, $currency, $currencyKey);
        });
    }

    public static function registerValidationRules(ValidationFactory $factory): void
    {
        $factory->extend('currency_code', static function (string $attribute, mixed $value, array $parameters, Validator $validator): bool {
            return MoneyMacros::passes(new CurrencyCode($parameters === [] ? null : array_values($parameters)), $attribute, $value, 'currency_code', $validator);
        });

        $factory->extend('money_amount', static function (string $attribute, mixed $value, array $parameters, Validator $validator): bool {
            $rule = MoneyAmount::in((string) ($parameters[0] ?? MoneyConfig::defaultCurrency()));

            return MoneyMacros::passes($rule->setData($validator->getData()), $attribute, $value, 'money_amount', $validator);
        });
    }

    /**
     * The values a collection macro aggregates: the items, a key/property of each, or a
     * callback's result.
     *
     * @param  Collection<array-key, mixed>|LazyCollection<array-key, mixed>  $collection
     * @return iterable<mixed>
     */
    public static function values(Collection|LazyCollection $collection, callable|string|null $value): iterable
    {
        if ($value === null) {
            return $collection;
        }

        return $collection->map(is_string($value) ? static fn (mixed $item): mixed => data_get($item, $value) : $value(...));
    }

    /**
     * `$request->money('price', 'EUR')` — int, decimal-string or float input to exact Money.
     * Floats go through their shortest round-trip decimal (JSON `1.1` is 110 cents, not 111).
     */
    public static function requestMoney(Request $request, string $key, Currency|string|null $currency = null, ?string $currencyKey = null): ?Money
    {
        $raw = $request->input($key);

        if ($raw === null || (is_string($raw) && trim($raw) === '')) {
            return null;
        }

        $amount = match (true) {
            is_int($raw) => (string) $raw,
            is_float($raw) => DecimalString::fromFloat($raw),
            is_string($raw) => trim($raw),
            default => throw InvalidAmount::notDecimal(get_debug_type($raw)),
        };

        $fromInput = $currencyKey === null ? null : $request->input($currencyKey);

        $currency = match (true) {
            $currency instanceof Currency => $currency,
            is_string($currency) && $currency !== '' => $currency,
            is_string($fromInput) && trim($fromInput) !== '' => $fromInput,
            default => MoneyConfig::defaultCurrency(),
        };

        return Money::ofMajor($amount, $currency);
    }

    /**
     * Run a rule object behind a string alias and hand its message to the validator.
     */
    public static function passes(CurrencyCode|MoneyAmount $rule, string $attribute, mixed $value, string $alias, Validator $validator): bool
    {
        $failure = null;

        $rule->validate($attribute, $value, static function (string $message) use (&$failure): PotentiallyTranslatedString {
            return $failure = new PotentiallyTranslatedString($message, app(Translator::class));
        });

        if (! $failure instanceof PotentiallyTranslatedString) {
            return true;
        }

        $validator->setCustomMessages([$attribute.'.'.$alias => $failure->toString()]);

        return false;
    }
}
