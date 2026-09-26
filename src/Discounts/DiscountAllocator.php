<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Discounts;

use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidAllocation;
use RoundlyConsulting\Money\Exceptions\InvalidDiscount;
use RoundlyConsulting\Money\Money;

/**
 * Spreads an order-level discount over line totals in proportion to the lines, with the
 * largest-remainder method: the shares sum to the discount exactly and `0 ≤ share ≤ line`.
 *
 * Why a share never exceeds its line: share_i ≤ ⌈d·l_i / L⌉, and d ≤ L makes d·l_i / L ≤ l_i,
 * whose ceiling is still l_i because l_i is an integer.
 */
final class DiscountAllocator
{
    /**
     * @return non-empty-list<Money>
     */
    public function allocate(Money $discount, Money ...$lineTotals): array
    {
        if ($lineTotals === []) {
            throw InvalidAllocation::emptyRatios();
        }

        if ($discount->isNegative()) {
            throw InvalidDiscount::negative((string) $discount);
        }

        foreach ($lineTotals as $line) {
            if (! $line->isSameCurrency($discount)) {
                throw CurrencyMismatch::between($discount->currency(), $line->currency());
            }

            if ($line->isNegative()) {
                throw InvalidDiscount::negativeLine((string) $line);
            }
        }

        $lines = array_values($lineTotals);
        $total = Money::sum($lines);

        if ($discount->isGreaterThan($total)) {
            throw InvalidDiscount::exceedsLines((string) $discount, (string) $total);
        }

        if ($total->isZero()) {
            return array_map(static fn (Money $line): Money => Money::zero($line->currency()), $lines);
        }

        return $discount->allocate(...array_map(static fn (Money $line): string => $line->minor(), $lines));
    }
}
