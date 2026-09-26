<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Jobs;

use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use RoundlyConsulting\Money\Actions\RefreshExchangeRatesAction;
use RoundlyConsulting\Money\DataTransferObjects\RefreshExchangeRatesData;
use RoundlyConsulting\Money\Enums\EcbFeed;

/** Queued refresh; unique per source + feed for ten minutes. Holds scalars only. */
final class RefreshExchangeRates implements ShouldBeUnique, ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $source = 'ecb',
        public readonly string $feed = 'daily',
        public readonly ?string $from = null,
        public readonly ?string $to = null,
    ) {}

    public function uniqueId(): string
    {
        return $this->source.':'.$this->feed;
    }

    public function handle(RefreshExchangeRatesAction $action): void
    {
        $action->execute(new RefreshExchangeRatesData(
            $this->source,
            EcbFeed::from($this->feed),
            $this->from === null ? null : CarbonImmutable::parse($this->from),
            $this->to === null ? null : CarbonImmutable::parse($this->to),
        ));
    }
}
