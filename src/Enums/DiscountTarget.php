<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Enums;

use RoundlyConsulting\Enums\Helpers;

/** Which base a discount applies to. */
enum DiscountTarget: string
{
    use Helpers;

    case Subtotal = 'subtotal';
    case Shipping = 'shipping';
}
