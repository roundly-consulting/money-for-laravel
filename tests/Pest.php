<?php

declare(strict_types=1);

use RoundlyConsulting\Money\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/**
 * A seeded random canonical integer with up to `$maxDigits` digits (random sign).
 */
function randomInteger(int $maxDigits, bool $allowNegative = true): string
{
    $length = mt_rand(1, $maxDigits);
    $digits = (string) mt_rand(1, 9);

    for ($i = 1; $i < $length; $i++) {
        $digits .= (string) mt_rand(0, 9);
    }

    if (mt_rand(0, 20) === 0) {
        return '0';
    }

    return $allowNegative && mt_rand(0, 1) === 1 ? '-'.$digits : $digits;
}

/**
 * ICU versions swap NBSP / NNBSP / thin spaces and add bidi marks; compare normalised.
 */
function ws(string $formatted): string
{
    return strtr($formatted, ["\u{00A0}" => ' ', "\u{202F}" => ' ', "\u{2009}" => ' ', "\u{200E}" => '', "\u{200F}" => '', "\u{061C}" => '']);
}
