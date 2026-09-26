<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RoundlyConsulting\Money\Exchange\Converter;
use RoundlyConsulting\Money\Exchange\ExchangeManager;
use RoundlyConsulting\Money\Money;

final class ConvertMoneyCommand extends Command
{
    protected $signature = 'money:convert
        {amount : A major amount, e.g. 19.99}
        {from : Currency code}
        {to : Currency code}
        {--date= : Rate date (Y-m-d), default latest}
        {--driver= : Exchange driver, default money.exchange.default}';

    protected $description = 'Convert an amount and show the rate used';

    public function handle(ExchangeManager $exchange): int
    {
        $date = $this->option('date');
        $driver = $this->option('driver');

        $on = is_string($date) && $date !== '' ? CarbonImmutable::createFromFormat('!Y-m-d', $date) ?: null : null;
        $money = Money::ofMajor((string) $this->argument('amount'), (string) $this->argument('from'));

        $conversion = (new Converter($exchange->provider(is_string($driver) && $driver !== '' ? $driver : null)))
            ->convertWithRate($money, (string) $this->argument('to'), $on);

        $this->components->twoColumnDetail('Rate', $conversion->rate->decimal());
        $this->components->twoColumnDetail('Source', $conversion->rate->source);
        $this->components->twoColumnDetail('Date', $conversion->rate->date->toDateString());
        $this->components->twoColumnDetail((string) $conversion->original, (string) $conversion->converted);

        return self::SUCCESS;
    }
}
