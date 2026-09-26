<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Enums;

use RoundlyConsulting\Enums\Helpers;

/** The ECB reference-rate feeds: today, the last 90 days, or everything since 1999. */
enum EcbFeed: string
{
    use Helpers;

    case Daily = 'daily';
    case Recent = 'recent';
    case History = 'history';
}
