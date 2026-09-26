<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\DataTransferObjects;

use Carbon\CarbonImmutable;

final readonly class PruneExchangeRatesData
{
    /**
     * @param  CarbonImmutable  $before  rows effective before this date go
     * @param  bool  $includeManual  also delete 'manual' rows (kept by default)
     * @param  bool  $pretend  count only
     */
    public function __construct(
        public CarbonImmutable $before,
        public bool $includeManual = false,
        public bool $pretend = false,
    ) {}
}
