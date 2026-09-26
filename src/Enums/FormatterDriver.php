<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Enums;

use RoundlyConsulting\Enums\Helpers;

/** Which MoneyFormatter the container binds (`money.formatting.driver`). */
enum FormatterDriver: string
{
    use Helpers;

    /** Intl when ext-intl is loaded, the deterministic decimal formatter otherwise. */
    case Auto = 'auto';
    case Intl = 'intl';
    case Decimal = 'decimal';
}
