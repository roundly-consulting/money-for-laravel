<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

/**
 * An immutable multi-currency accumulator: one running amount per currency. Amounts that
 * return to zero drop out, so an empty bag and a bag of zeros are the same bag.
 */
final readonly class MoneyBag
{
    /**
     * @param  array<string, Money>  $amounts  keyed by currency code, never zero
     */
    private function __construct(private array $amounts) {}

    public static function of(Money ...$monies): self
    {
        return (new self([]))->add(...$monies);
    }

    public function add(Money ...$monies): self
    {
        $amounts = $this->amounts;

        foreach ($monies as $money) {
            $code = $money->currency()->code;
            $amounts[$code] = isset($amounts[$code]) ? $amounts[$code]->add($money) : $money;

            if ($amounts[$code]->isZero()) {
                unset($amounts[$code]);
            }
        }

        ksort($amounts, SORT_STRING);

        return new self($amounts);
    }

    public function subtract(Money ...$monies): self
    {
        return $this->add(...array_map(static fn (Money $money): Money => $money->negate(), $monies));
    }

    /** The amount held in a currency — zero when there is none. */
    public function get(Currency|string $currency): Money
    {
        $currency = $currency instanceof Currency ? $currency : Currency::of($currency);
        $money = $this->amounts[$currency->code] ?? null;

        return $money !== null && $money->currency()->equals($currency) ? $money : Money::zero($currency);
    }

    /** @return list<Currency> sorted by code */
    public function currencies(): array
    {
        return array_values(array_map(static fn (Money $money): Currency => $money->currency(), $this->amounts));
    }

    /** @return list<Money> sorted by currency code */
    public function all(): array
    {
        return array_values($this->amounts);
    }

    public function isEmpty(): bool
    {
        return $this->amounts === [];
    }
}
