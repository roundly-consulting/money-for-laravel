<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Enums;

use RoundlyConsulting\Enums\Helpers;

/** How several discounts combine: compounding on the running remainder, each on the original base, or only the single best one. */
enum StackingStrategy: string
{
    use Helpers;

    case Sequential = 'sequential';
    case Additive = 'additive';
    case BestOf = 'best_of';
}
