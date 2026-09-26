<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RoundlyConsulting\Money\Actions\PruneExchangeRatesAction;
use RoundlyConsulting\Money\DataTransferObjects\PruneExchangeRatesData;

final class PruneExchangeRatesCommand extends Command
{
    protected $signature = 'money:rates:prune
        {--before= : Delete rows effective before this date (Y-m-d)}
        {--keep-days= : Or: keep only this many days}
        {--include-manual : Also delete manual rows}
        {--pretend : Only count}';

    protected $description = 'Delete old exchange rates (manual rows are kept by default)';

    public function handle(PruneExchangeRatesAction $action): int
    {
        $before = $this->before();

        if ($before === null) {
            $this->components->error('Pass --before=Y-m-d or --keep-days=N.');

            return self::INVALID;
        }

        $count = $action->execute(new PruneExchangeRatesData($before, (bool) $this->option('include-manual'), (bool) $this->option('pretend')));

        $this->components->info(($this->option('pretend') ? 'Would delete ' : 'Deleted ').$count.' exchange rate(s) before '.$before->toDateString().'.');

        return self::SUCCESS;
    }

    private function before(): ?CarbonImmutable
    {
        $before = $this->option('before');
        $keepDays = $this->option('keep-days');

        if (is_string($before) && $before !== '') {
            return CarbonImmutable::createFromFormat('!Y-m-d', $before) ?: null;
        }

        if (is_string($keepDays) && ctype_digit($keepDays)) {
            return CarbonImmutable::now((string) config('money.exchange.timezone'))->startOfDay()->subDays((int) $keepDays);
        }

        return null;
    }
}
