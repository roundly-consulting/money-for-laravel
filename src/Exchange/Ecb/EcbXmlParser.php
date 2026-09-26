<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange\Ecb;

use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Math\DecimalString;
use SimpleXMLElement;

/**
 * Parses the ECB euro reference-rate feeds into `date → code → rate` (decimal strings).
 * Any `<!DOCTYPE` is refused before parsing (XXE / entity expansion), the network is off
 * (LIBXML_NONET), and every row must be a plain positive decimal.
 *
 * @internal
 */
final class EcbXmlParser
{
    private const string NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    /**
     * @return array<string, array<string, string>> newest date first
     */
    public static function parse(string $xml): array
    {
        if (! function_exists('simplexml_load_string')) {
            throw ExchangeRateFetchFailed::missingExtension('simplexml');
        }

        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw ExchangeRateFetchFailed::malformed('a DOCTYPE is not allowed');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $document = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (! $document instanceof SimpleXMLElement) {
            throw ExchangeRateFetchFailed::malformed('not well-formed XML');
        }

        $days = [];

        foreach ($document->children(self::NAMESPACE)->Cube->Cube ?? [] as $day) {
            $date = (string) $day->attributes()['time'];

            if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $date) !== 1) {
                throw ExchangeRateFetchFailed::malformed("invalid date [{$date}]");
            }

            foreach ($day->Cube as $row) {
                $attributes = $row->attributes();
                $code = (string) $attributes['currency'];
                $rate = (string) $attributes['rate'];

                if (preg_match('/\A[A-Z]{3}\z/', $code) !== 1 || ! DecimalString::isValid($rate) || DecimalString::normalize($rate) === '0' || str_starts_with($rate, '-')) {
                    throw ExchangeRateFetchFailed::malformed("invalid row [{$code}] [{$rate}] on {$date}");
                }

                $days[$date][$code] = DecimalString::normalize($rate);
            }
        }

        if ($days === []) {
            throw ExchangeRateFetchFailed::malformed('no rates found');
        }

        krsort($days, SORT_STRING);

        return $days;
    }
}
