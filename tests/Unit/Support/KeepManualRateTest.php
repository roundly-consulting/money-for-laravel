<?php

declare(strict_types=1);

use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\Money\Support\KeepManualRate;

it('keeps a stored manual row in every engine dialect', function (string $grammar, string $incoming, string $expected): void {
    $sql = (new KeepManualRate('money_exchange_rates', 'rate', $incoming))->getValue(new $grammar(DB::connection()));

    expect($sql)->toBe($expected);
})->with([
    'pgsql / sqlite' => [PostgresGrammar::class, 'excluded', 'case when "money_exchange_rates"."source" = \'manual\' and "excluded"."source" <> \'manual\' then "money_exchange_rates"."rate" else "excluded"."rate" end'],
    'MySQL values()' => [MySqlGrammar::class, 'values', 'case when `money_exchange_rates`.`source` = \'manual\' and values(`source`) <> \'manual\' then `money_exchange_rates`.`rate` else values(`rate`) end'],
    'MySQL row alias' => [MySqlGrammar::class, 'alias', 'case when `money_exchange_rates`.`source` = \'manual\' and `laravel_upsert_alias`.`source` <> \'manual\' then `money_exchange_rates`.`rate` else `laravel_upsert_alias`.`rate` end'],
]);
