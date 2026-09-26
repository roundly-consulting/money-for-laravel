<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use RoundlyConsulting\Money\Contracts\CurrencyRegistry;
use RoundlyConsulting\Money\Currency;

final class ListCurrenciesCommand extends Command
{
    protected $signature = 'money:currencies
        {--iso : Only the bundled ISO 4217 currencies}
        {--custom : Only custom currencies}
        {--code=* : Only these codes}';

    protected $description = 'List the currencies the money registry knows';

    public function handle(CurrencyRegistry $registry): int
    {
        $currencies = match (true) {
            (bool) $this->option('iso') => $registry->iso(),
            (bool) $this->option('custom') => $registry->custom(),
            default => $registry->all(),
        };

        $codes = array_map(static fn (string $code): string => strtoupper(trim($code)), $this->codes());

        if ($codes !== []) {
            $currencies = array_values(array_filter(
                $currencies,
                static fn (Currency $currency): bool => in_array($currency->code, $codes, true),
            ));
        }

        $this->table(
            ['Code', 'Numeric', 'Exponent', 'Symbol', 'Name', 'Origin'],
            array_map(static fn (Currency $currency): array => [
                $currency->code,
                $currency->numericCodeString() ?? '—',
                (string) $currency->exponent,
                $currency->displaySymbol(),
                $currency->name,
                $currency->iso ? 'ISO 4217' : 'custom',
            ], $currencies),
        );

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_values(array_filter(Arr::wrap($this->option('code')), is_string(...)));
    }
}
