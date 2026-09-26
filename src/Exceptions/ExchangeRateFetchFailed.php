<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exceptions;

final class ExchangeRateFetchFailed extends MoneyException
{
    public static function http(string $url, int $status): self
    {
        return new self("Fetching exchange rates from [{$url}] failed with HTTP {$status}.");
    }

    public static function malformed(string $reason): self
    {
        return new self("The exchange-rate feed is malformed: {$reason}.");
    }

    public static function tooLarge(int $bytes, int $max): self
    {
        return new self("The exchange-rate feed is {$bytes} bytes; at most {$max} are accepted.");
    }

    public static function missingExtension(string $extension): self
    {
        return new self("The ECB exchange-rate provider needs ext-{$extension}.");
    }
}
