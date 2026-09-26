<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\DataTransferObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\Money\Enums\EcbFeed;

final readonly class RefreshExchangeRatesData
{
    /**
     * @param  string  $source  an exchange driver implementing ExchangeRateSource
     * @param  CarbonImmutable|null  $from  default: 7 days (daily), 90 days (recent) or 1999-01-04 (history) before $to
     * @param  CarbonImmutable|null  $to  default: today in money.exchange.timezone
     */
    public function __construct(
        public string $source = 'ecb',
        public EcbFeed $feed = EcbFeed::Daily,
        public ?CarbonImmutable $from = null,
        public ?CarbonImmutable $to = null,
    ) {}
}
