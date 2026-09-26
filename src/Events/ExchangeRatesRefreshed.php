<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Events;

use RoundlyConsulting\Money\DataTransferObjects\RefreshResult;

final class ExchangeRatesRefreshed
{
    public function __construct(public readonly RefreshResult $result) {}
}
