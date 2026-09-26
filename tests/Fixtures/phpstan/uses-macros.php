<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Tests\Fixtures\PHPStan;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use RoundlyConsulting\Money\Money;

/**
 * Analysed by the package's own `composer analyse`: proves the reflection extension makes
 * every macro visible with its real signature, named arguments included.
 */
final class UsesMacros
{
    public function schema(Blueprint $table): ColumnDefinition
    {
        $table->money('compare_at_price', currency: 'currency', nullable: true);
        $table->money('budget', currency: false);
        $table->moneyJson('snapshot', nullable: true);
        $table->currencyCode();

        return $table->money('price');
    }

    /**
     * @param  Collection<int, Money>  $monies
     * @param  LazyCollection<int, Money>  $lazy
     */
    public function aggregates(Collection $monies, LazyCollection $lazy): Money
    {
        $monies->minMoney();
        $monies->maxMoney(fn (Money $money): Money => $money);
        $lazy->avgMoney(rounding: \RoundingMode::HalfEven);

        return $monies->sumMoney(currencyIfEmpty: 'EUR');
    }

    public function request(Request $request): ?Money
    {
        return $request->money('price', currencyKey: 'currency');
    }
}
