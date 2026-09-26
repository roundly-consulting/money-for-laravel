<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Tax;

use RoundlyConsulting\Money\Money;

/**
 * Re-aggregates per-line breakdowns by rate without re-rounding — whether tax is rounded
 * per line or per total stays the caller's policy.
 */
final readonly class TaxSummary
{
    /**
     * @param  list<TaxBreakdown>  $lines
     */
    private function __construct(private array $lines) {}

    public static function of(TaxBreakdown ...$lines): self
    {
        return new self(array_values($lines));
    }

    /**
     * One breakdown per distinct rate, sorted by rate.
     *
     * @return list<TaxBreakdown>
     */
    public function perRate(): array
    {
        $groups = [];

        foreach ($this->lines as $line) {
            $key = $line->rate->percentage()->value();
            $existing = $groups[$key] ?? null;

            $groups[$key] = $existing === null ? $line : new TaxBreakdown(
                $existing->net->add($line->net),
                $existing->tax->add($line->tax),
                $existing->gross->add($line->gross),
                $existing->rate,
            );
        }

        $groups = array_values($groups);

        usort($groups, static fn (TaxBreakdown $a, TaxBreakdown $b): int => $a->rate->percentage()->compareTo($b->rate->percentage()));

        return $groups;
    }

    public function net(): Money
    {
        return Money::sum(array_map(static fn (TaxBreakdown $line): Money => $line->net, $this->lines));
    }

    public function tax(): Money
    {
        return Money::sum(array_map(static fn (TaxBreakdown $line): Money => $line->tax, $this->lines));
    }

    public function gross(): Money
    {
        return Money::sum(array_map(static fn (TaxBreakdown $line): Money => $line->gross, $this->lines));
    }
}
