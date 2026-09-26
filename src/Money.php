<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RoundingMode;
use RoundlyConsulting\Money\Contracts\CurrencyConverter;
use RoundlyConsulting\Money\Contracts\MoneyFormatter;
use RoundlyConsulting\Money\Contracts\MoneyParser;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\DivisionByZero;
use RoundlyConsulting\Money\Exceptions\EmptyMoneyCollection;
use RoundlyConsulting\Money\Exceptions\InvalidAllocation;
use RoundlyConsulting\Money\Exceptions\InvalidAmount;
use RoundlyConsulting\Money\Exceptions\InvalidMoneyValue;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Formatting\FormatOptions;
use RoundlyConsulting\Money\Math\Calculator;
use RoundlyConsulting\Money\Math\DecimalString;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Math\MinorUnits;
use RoundlyConsulting\Money\Math\Rounder;
use Stringable;

/**
 * An immutable amount of money: an arbitrary-precision number of minor units (cents,
 * satoshis, wei) held as a canonical integer string, plus its {@see Currency}.
 *
 * - The unit is always in the factory name — {@see self::ofMinor()} or {@see self::ofMajor()};
 *   there is deliberately no ambiguous `of()`.
 * - No float is ever accepted or returned.
 * - Arithmetic and ordering work within one currency only ({@see CurrencyMismatch}).
 * - Lossless operations never round; lossy ones round exactly once, with an explicit
 *   `\RoundingMode` (default HalfAwayFromZero).
 * - At most {@see self::MAX_DIGITS} significant minor digits in memory.
 *
 * @implements Arrayable<string, string>
 */
final readonly class Money implements Arrayable, JsonSerializable, Stringable
{
    /** In-memory cap on significant minor digits (MySQL's maximum DECIMAL precision). */
    public const int MAX_DIGITS = IntegerString::MAX_DIGITS;

    /** The largest number of parts {@see self::split()} produces. */
    public const int MAX_SPLIT = 10_000;

    private function __construct(
        private string $minor,
        private Currency $currency,
    ) {}

    // ── construction ────────────────────────────────────────────────────────

    /** An amount of minor units: `ofMinor(1050, 'EUR')` is 10.50 €. Int or integer string. */
    public static function ofMinor(int|string $minor, Currency|string $currency): self
    {
        return new self(IntegerString::normalize($minor), self::currencyFrom($currency));
    }

    /**
     * An amount of major units as an int or a plain decimal string: `ofMajor('10.50', 'EUR')`.
     * More fraction digits than the currency exponent need a rounding mode.
     */
    public static function ofMajor(int|string $amount, Currency|string $currency, ?RoundingMode $rounding = null): self
    {
        $currency = self::currencyFrom($currency);

        $minor = DecimalString::toScaledInteger($amount, $currency->exponent, $rounding);

        return new self(IntegerString::capped($minor, 'ofMajor'), $currency);
    }

    /**
     * An integer at another decimal scale, rescaled to the currency exponent: Apple's
     * milli-units are scale 3, Google's micros scale 6.
     */
    public static function ofScaled(int|string $value, int $scale, Currency|string $currency, ?RoundingMode $rounding = null): self
    {
        $currency = self::currencyFrom($currency);
        $value = IntegerString::normalize($value);

        if ($scale < 0 || $scale > MinorUnits::MAX_SCALE) {
            throw InvalidAmount::scale($scale, MinorUnits::MAX_SCALE);
        }

        if ($scale > $currency->exponent && $rounding === null) {
            [, $remainder] = Calculator::divmod($value, Calculator::pow10($scale - $currency->exponent));

            if ($remainder !== '0') {
                throw RoundingNecessary::for(IntegerString::toDecimal($value, $scale), $currency->exponent);
            }
        }

        return new self(
            MinorUnits::rescale($value, $scale, $currency->exponent, $rounding ?? RoundingMode::HalfAwayFromZero),
            $currency,
        );
    }

    public static function zero(Currency|string $currency): self
    {
        return new self('0', self::currencyFrom($currency));
    }

    /** Parse localized input ("1 234,50 €") through the bound MoneyParser. */
    public static function parse(string $input, Currency|string|null $currency = null, ?string $locale = null): self
    {
        return app(MoneyParser::class)->parse($input, $currency, $locale);
    }

    /**
     * Rebuild from the {@see self::toArray()} / JSON shape. `minor` (int or integer string —
     * never a float) and/or `decimal` plus `currency`; when both amounts are present they
     * must agree, so a payload tampered with on its way through a client is refused.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $currency = $data['currency'] ?? throw InvalidAmount::missingKey('currency');

        if (! is_string($currency) && ! $currency instanceof Currency) {
            throw InvalidAmount::missingKey('currency');
        }

        $minor = $data['minor'] ?? null;
        $decimal = $data['decimal'] ?? null;

        if (is_float($minor)) {
            throw InvalidAmount::float('a JSON number above PHP_INT_MAX decoded to a float');
        }

        if ($minor !== null && ! is_int($minor) && ! is_string($minor)) {
            throw InvalidAmount::notInteger(get_debug_type($minor));
        }

        if ($decimal !== null && ! is_string($decimal)) {
            throw InvalidAmount::notDecimal(get_debug_type($decimal));
        }

        $fromMinor = $minor === null ? null : self::ofMinor($minor, $currency);
        $fromDecimal = $decimal === null ? null : self::ofMajor($decimal, $currency);

        if ($fromMinor !== null && $fromDecimal !== null && ! $fromMinor->equals($fromDecimal)) {
            throw InvalidAmount::inconsistent($fromMinor->minor(), $decimal);
        }

        return $fromMinor ?? $fromDecimal ?? throw InvalidAmount::missingKey('minor');
    }

    // ── accessors ───────────────────────────────────────────────────────────

    /** The canonical integer string of minor units: "1050", "-5", "0". */
    public function minor(): string
    {
        return $this->minor;
    }

    /** The explicit int bridge; throws AmountOverflow::int64 outside the 64-bit range. */
    public function minorInt(): int
    {
        return IntegerString::toInt($this->minor);
    }

    /** Whether {@see self::minorInt()} would succeed. */
    public function fitsInt(): bool
    {
        return IntegerString::fitsInt($this->minor);
    }

    /** Significant digits of |minor| ("0" has one). */
    public function digits(): int
    {
        return IntegerString::digits($this->minor);
    }

    public function currency(): Currency
    {
        return $this->currency;
    }

    /**
     * Locale-free major amount: `-?\d+(\.\d{exponent})?`, '.' separator, no grouping. With
     * `$trimTrailingZeros` the trailing fraction zeros go, and so does a bare '.'.
     */
    public function toDecimal(bool $trimTrailingZeros = false): string
    {
        return IntegerString::toDecimal($this->minor, $this->currency->exponent, $trimTrailingZeros);
    }

    /** The amount as an integer string at another decimal scale (0..36). */
    public function toScaled(int $scale, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): string
    {
        return MinorUnits::rescale($this->minor, $this->currency->exponent, $scale, $rounding);
    }

    // ── arithmetic ──────────────────────────────────────────────────────────

    public function add(self ...$others): self
    {
        $minor = $this->minor;

        foreach ($others as $other) {
            $this->assertSameCurrency($other);
            $minor = Calculator::add($minor, $other->minor);
        }

        return $this->with(IntegerString::capped($minor, 'add'));
    }

    public function subtract(self ...$others): self
    {
        $minor = $this->minor;

        foreach ($others as $other) {
            $this->assertSameCurrency($other);
            $minor = Calculator::sub($minor, $other->minor);
        }

        return $this->with(IntegerString::capped($minor, 'subtract'));
    }

    /**
     * An integer factor is exact; a decimal factor ('1.19') or a Ratio rounds once.
     */
    public function multiply(int|string|Ratio $factor, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        [$numerator, $denominator] = self::fraction($factor);

        return $this->with(self::scale($this->minor, $numerator, $denominator, $rounding, 'multiply'));
    }

    public function divide(int|string|Ratio $divisor, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        [$numerator, $denominator] = self::fraction($divisor);

        if ($numerator === '0') {
            throw DivisionByZero::create();
        }

        return $this->with(self::scale($this->minor, $denominator, $numerator, $rounding, 'divide'));
    }

    /** `percentage('8.5')` = this × 8.5 / 100, rounded once. */
    public function percentage(Percentage|int|string $percent, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        $percent = $percent instanceof Percentage ? $percent : Percentage::of($percent);

        return $this->multiply($percent->toRatio(), $rounding);
    }

    /** Round to a multiple of `$minorIncrement` minor units — cash rounding, e.g. CHF 5. */
    public function roundTo(int|string $minorIncrement, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        $increment = IntegerString::normalize($minorIncrement);

        if (IntegerString::sign($increment) < 1) {
            throw InvalidAmount::increment($increment);
        }

        $units = Rounder::divide($this->minor, $increment, $rounding);

        return $this->with(IntegerString::capped(Calculator::mul($units, $increment), 'roundTo'));
    }

    public function negate(): self
    {
        return $this->with(IntegerString::negate($this->minor));
    }

    public function abs(): self
    {
        return $this->with(IntegerString::abs($this->minor));
    }

    /**
     * Split by ratios without losing or inventing a minor unit: every part is within one
     * minor unit of its exact share, the parts always sum back to this amount, and leftover
     * units go to the largest remainders (ties to the lower index).
     *
     * @return non-empty-list<self>
     */
    public function allocate(int|string ...$ratios): array
    {
        $weights = self::weights(array_values($ratios));
        $total = '0';

        foreach ($weights as $weight) {
            $total = Calculator::add($total, $weight);
        }

        if ($total === '0') {
            throw InvalidAllocation::zeroTotal();
        }

        $magnitude = IntegerString::abs($this->minor);
        $shares = [];
        $remainders = [];
        $allocated = '0';

        foreach ($weights as $index => $weight) {
            [$share, $remainder] = Calculator::divmod(Calculator::mul($magnitude, $weight), $total);
            $shares[$index] = $share;
            $remainders[$index] = $remainder;
            $allocated = Calculator::add($allocated, $share);
        }

        $leftover = IntegerString::toInt(Calculator::sub($magnitude, $allocated));

        $order = array_keys($remainders);
        usort($order, static fn (int $a, int $b): int => IntegerString::compare($remainders[$b], $remainders[$a]) ?: $a <=> $b);

        foreach (array_slice($order, 0, $leftover) as $index) {
            $shares[$index] = Calculator::add($shares[$index], '1');
        }

        $negative = IntegerString::isNegative($this->minor);
        $parts = [];

        foreach ($shares as $share) {
            $parts[] = $this->with($negative ? IntegerString::negate($share) : $share);
        }

        return $parts;
    }

    /**
     * `$parts` equal shares (1..10 000), largest-remainder distributed.
     *
     * @return non-empty-list<self>
     */
    public function split(int $parts): array
    {
        if ($parts < 1 || $parts > self::MAX_SPLIT) {
            throw InvalidAllocation::parts($parts, self::MAX_SPLIT);
        }

        return $this->allocate(...array_fill(0, $parts, 1));
    }

    /** The exact ratio this / other (same currency). */
    public function ratioTo(self $other): Ratio
    {
        $this->assertSameCurrency($other);

        if ($other->minor === '0') {
            throw DivisionByZero::create();
        }

        return Ratio::fromIntegers($this->minor, $other->minor);
    }

    // ── comparison ──────────────────────────────────────────────────────────

    /** Same amount and same currency (code and exponent). Never throws. */
    public function equals(self $other): bool
    {
        return $this->minor === $other->minor && $this->currency->equals($other->currency);
    }

    public function isSameCurrency(self $other): bool
    {
        return $this->currency->equals($other->currency);
    }

    /** -1, 0 or 1; throws CurrencyMismatch across currencies. */
    public function compareTo(self $other): int
    {
        $this->assertSameCurrency($other);

        return IntegerString::compare($this->minor, $other->minor);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function isZero(): bool
    {
        return $this->minor === '0';
    }

    public function isPositive(): bool
    {
        return IntegerString::sign($this->minor) > 0;
    }

    public function isNegative(): bool
    {
        return IntegerString::isNegative($this->minor);
    }

    // ── aggregates ──────────────────────────────────────────────────────────

    /**
     * @param  iterable<self>  $monies
     */
    public static function sum(iterable $monies, Currency|string|null $currencyIfEmpty = null): self
    {
        $total = null;

        foreach ($monies as $money) {
            $money = self::element($money, 'sum');
            $total = $total === null ? $money : $total->add($money);
        }

        if ($total !== null) {
            return $total;
        }

        if ($currencyIfEmpty === null) {
            throw EmptyMoneyCollection::for('sum');
        }

        return self::zero($currencyIfEmpty);
    }

    /**
     * @param  iterable<self>  $monies
     */
    public static function min(iterable $monies): self
    {
        $min = null;

        foreach ($monies as $money) {
            $money = self::element($money, 'min');
            $min = $min === null || $money->isLessThan($min) ? $money : $min;
        }

        return $min ?? throw EmptyMoneyCollection::for('min');
    }

    /**
     * @param  iterable<self>  $monies
     */
    public static function max(iterable $monies): self
    {
        $max = null;

        foreach ($monies as $money) {
            $money = self::element($money, 'max');
            $max = $max === null || $money->isGreaterThan($max) ? $money : $max;
        }

        return $max ?? throw EmptyMoneyCollection::for('max');
    }

    /**
     * The mean, rounded once.
     *
     * @param  iterable<self>  $monies
     */
    public static function average(iterable $monies, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): self
    {
        $total = null;
        $count = 0;

        foreach ($monies as $money) {
            $money = self::element($money, 'average');
            $total = $total === null ? $money : $total->add($money);
            $count++;
        }

        if ($total === null) {
            throw EmptyMoneyCollection::for('average');
        }

        return $total->divide($count, $rounding);
    }

    // ── container conveniences (the only methods that resolve services) ────

    /** Locale-aware output through the bound MoneyFormatter (intl, or the decimal fallback). */
    public function format(?string $locale = null, ?FormatOptions $options = null): string
    {
        return app(MoneyFormatter::class)->format($this, $locale, $options);
    }

    /**
     * Convert through the bound CurrencyConverter (the configured exchange driver); rounds
     * once, default money.exchange.rounding.
     */
    public function convertTo(Currency|string $currency, ?CarbonInterface $on = null, ?RoundingMode $rounding = null): self
    {
        return app(CurrencyConverter::class)->convert($this, $currency, $on, $rounding);
    }

    // ── serialization ───────────────────────────────────────────────────────

    /**
     * `minor` is a string on purpose: a JSON number above 2^53 is silently corrupted by
     * JavaScript, and by json_decode() above PHP_INT_MAX.
     *
     * @return array{minor: string, decimal: string, currency: string}
     */
    public function toArray(): array
    {
        return [
            'minor' => $this->minor,
            'decimal' => $this->toDecimal(),
            'currency' => $this->currency->code,
        ];
    }

    /**
     * @return array{minor: string, decimal: string, currency: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Deterministic and locale-free, for logs and messages: "-0.50 EUR". */
    public function __toString(): string
    {
        return $this->toDecimal().' '.$this->currency->code;
    }

    // ── internals ───────────────────────────────────────────────────────────

    private function with(string $minor): self
    {
        return new self($minor, $this->currency);
    }

    private function assertSameCurrency(self $other): void
    {
        if (! $this->currency->equals($other->currency)) {
            throw CurrencyMismatch::between($this->currency, $other->currency);
        }
    }

    private static function currencyFrom(Currency|string $currency): Currency
    {
        return $currency instanceof Currency ? $currency : Currency::of($currency);
    }

    private static function element(mixed $value, string $operation): self
    {
        if (! $value instanceof self) {
            throw InvalidMoneyValue::notMoney($operation, $value);
        }

        return $value;
    }

    /**
     * A factor as an exact integer fraction.
     *
     * @return list<string> [numerator, denominator]
     */
    private static function fraction(int|string|Ratio $factor): array
    {
        if ($factor instanceof Ratio) {
            return [$factor->numerator(), $factor->denominator()];
        }

        $decimal = DecimalString::normalize($factor);

        return [DecimalString::unscaled($decimal), Calculator::pow10(DecimalString::scale($decimal))];
    }

    /** minor × numerator / denominator, rounded once and capped. */
    private static function scale(string $minor, string $numerator, string $denominator, RoundingMode $rounding, string $operation): string
    {
        return IntegerString::capped(
            Rounder::divide(Calculator::mul($minor, $numerator), $denominator, $rounding),
            $operation,
        );
    }

    /**
     * Allocation ratios scaled to non-negative integers of a common scale.
     *
     * @param  list<int|string>  $ratios
     * @return non-empty-list<string>
     */
    private static function weights(array $ratios): array
    {
        if ($ratios === []) {
            throw InvalidAllocation::emptyRatios();
        }

        $decimals = array_map(static fn (int|string $ratio): string => DecimalString::normalize($ratio), $ratios);
        $scale = max(array_map(DecimalString::scale(...), $decimals));

        $weights = [];

        foreach ($decimals as $decimal) {
            if (IntegerString::isNegative($decimal)) {
                throw InvalidAllocation::negativeRatio($decimal);
            }

            $weights[] = DecimalString::toScaledInteger($decimal, $scale);
        }

        return $weights;
    }
}
