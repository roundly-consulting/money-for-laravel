<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Database\Factories;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use RoundlyConsulting\Money\Models\CurrencyRate;

/** @extends Factory<CurrencyRate> */
final class CurrencyRateFactory extends Factory
{
    protected $model = CurrencyRate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'base_currency' => 'EUR',
            'quote_currency' => $this->faker->randomElement(['USD', 'CZK', 'GBP', 'JPY', 'CHF']),
            'rate' => (string) $this->faker->randomFloat(4, 0.5, 200),
            'effective_date' => CarbonImmutable::today(),
            'source' => 'manual',
        ];
    }

    public function pair(string $base, string $quote, string $rate): self
    {
        return $this->state(['base_currency' => $base, 'quote_currency' => $quote, 'rate' => $rate]);
    }

    public function on(string $date): self
    {
        return $this->state(['effective_date' => $date]);
    }

    public function from(string $source): self
    {
        return $this->state(['source' => $source]);
    }
}
