<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Tests\Fixtures\Models;

use RoundlyConsulting\Money\Models\CurrencyRate;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

final class CustomCurrencyRate extends CurrencyRate
{
    use CountsCreations;
}
