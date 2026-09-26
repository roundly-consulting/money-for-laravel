<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\Isolatable;
use RoundlyConsulting\Money\Actions\RefreshExchangeRatesAction;
use RoundlyConsulting\Money\DataTransferObjects\RefreshExchangeRatesData;
use RoundlyConsulting\Money\Enums\EcbFeed;
use RoundlyConsulting\Money\Jobs\RefreshExchangeRates;

final class RefreshExchangeRatesCommand extends Command implements Isolatable
{
    protected $signature = 'money:rates:refresh
        {source=ecb : An exchange driver that can be fetched from}
        {--feed=daily : daily, recent (90 days) or history (since 1999)}
        {--from= : First date (Y-m-d)}
        {--to= : Last date (Y-m-d)}
        {--queue : Dispatch a queued job instead of running now}';

    protected $description = 'Fetch exchange rates into the money rates table';

    public function handle(RefreshExchangeRatesAction $action): int
    {
        $source = (string) $this->argument('source');
        $feed = EcbFeed::tryFrom((string) $this->option('feed'));

        if ($feed === null) {
            $this->components->error('The feed must be one of: '.implode(', ', EcbFeed::values()->all()).'.');

            return self::INVALID;
        }

        $from = $this->date('from');
        $to = $this->date('to');

        if ($this->option('queue')) {
            dispatch(new RefreshExchangeRates($source, $feed->value, $from?->toDateString(), $to?->toDateString()));
            $this->components->info("Queued a {$feed->value} refresh from [{$source}].");

            return self::SUCCESS;
        }

        $result = $action->execute(new RefreshExchangeRatesData($source, $feed, $from, $to));

        $this->components->twoColumnDetail('Source', $result->source);
        $this->components->twoColumnDetail('Stored', (string) $result->stored);
        $this->components->twoColumnDetail('Kept manual rows', (string) $result->skippedManual);
        $this->components->twoColumnDetail('Skipped unknown currencies', (string) $result->skippedUnknownCurrency);
        $this->components->twoColumnDetail('Latest date', $result->latestDate?->toDateString() ?? '—');

        return self::SUCCESS;
    }

    private function date(string $option): ?CarbonImmutable
    {
        $value = $this->option($option);

        return is_string($value) && $value !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $value) ?: null : null;
    }
}
