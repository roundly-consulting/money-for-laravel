<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Exceptions\ExchangeRateFetchFailed;
use RoundlyConsulting\Money\Exchange\Ecb\EcbXmlParser;

function ecbFixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../../Fixtures/ecb/'.$name.'.xml');
}

it('parses the daily and 90-day feeds newest first', function (): void {
    $daily = EcbXmlParser::parse(ecbFixture('daily'));
    $recent = EcbXmlParser::parse(ecbFixture('hist-90d-trimmed'));

    expect(array_keys($daily))->toBe(['2026-09-25'])
        ->and($daily['2026-09-25']['USD'])->toBe('1.1403')
        ->and($daily['2026-09-25']['ISK'])->toBe('136.6')
        ->and($daily['2026-09-25'])->toHaveCount(29)
        ->and(array_keys($recent))->toBe(['2026-09-25', '2026-09-24', '2026-09-23', '2026-09-22']);
});

it('refuses DOCTYPEs, malformed rows and empty documents', function (string $xml): void {
    EcbXmlParser::parse($xml);
})->throws(ExchangeRateFetchFailed::class)->with([
    'doctype' => [fn () => ecbFixture('doctype')],
    'comma decimal' => [fn () => ecbFixture('malformed')],
    'not xml' => ['<html>'],
    'empty' => ['<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube/></gesmes:Envelope>'],
    'bad date' => ['<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="yesterday"><Cube currency="USD" rate="1.1"/></Cube></Cube></gesmes:Envelope>'],
    'zero rate' => ['<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="2026-09-25"><Cube currency="USD" rate="0.00"/></Cube></Cube></gesmes:Envelope>'],
    'bad code' => ['<?xml version="1.0"?><gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref"><Cube><Cube time="2026-09-25"><Cube currency="usd" rate="1"/></Cube></Cube></gesmes:Envelope>'],
]);
