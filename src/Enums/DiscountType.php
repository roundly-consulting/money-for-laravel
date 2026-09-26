<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Enums;

use RoundlyConsulting\Enums\Helpers;

/** What a discount removes: a fixed amount or a percentage of its base. */
enum DiscountType: string
{
    use Helpers;

    case Fixed = 'fixed';
    case Percentage = 'percentage';
}
