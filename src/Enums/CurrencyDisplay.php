<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Enums;

use RoundlyConsulting\Enums\Helpers;

/** How a formatted amount shows its currency. */
enum CurrencyDisplay: string
{
    use Helpers;

    case Symbol = 'symbol';
    case Code = 'code';
    case None = 'none';
}
