<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Events;

/** Fired before the failure is rethrown — hook alerting here. Existing rows are untouched. */
final class ExchangeRatesRefreshFailed
{
    public function __construct(
        public readonly string $source,
        public readonly string $message,
    ) {}
}
