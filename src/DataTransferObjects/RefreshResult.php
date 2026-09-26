<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\DataTransferObjects;

use Carbon\CarbonImmutable;

final readonly class RefreshResult
{
    public function __construct(
        public string $source,
        public int $stored,
        public int $skippedManual,
        public int $skippedUnknownCurrency,
        public ?CarbonImmutable $latestDate,
    ) {}
}
