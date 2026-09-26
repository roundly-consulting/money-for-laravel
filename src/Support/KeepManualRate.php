<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * One upsert assignment of the rates table that never lets a non-manual row overwrite a
 * stored manual one — decided per row inside the statement:
 *
 *     col = CASE WHEN stored.source = 'manual' AND incoming.source <> 'manual'
 *                THEN stored.col ELSE incoming.col END
 *
 * Identifiers are wrapped by the grammar compiling the upsert; nothing here is input.
 *
 * @internal
 */
final readonly class KeepManualRate implements Expression
{
    /**
     * @param  'excluded'|'values'|'alias'  $incoming  how the engine names the proposed row:
     *                                                 sqlite/pgsql `excluded.col`, MySQL `values(col)`
     *                                                 or Laravel's `laravel_upsert_alias.col`
     */
    public function __construct(
        private string $table,
        private string $column,
        private string $incoming,
    ) {}

    public function getValue(Grammar $grammar): string
    {
        $stored = fn (string $column): string => $grammar->wrapTable($this->table).'.'.$grammar->wrap($column);

        $incoming = fn (string $column): string => match ($this->incoming) {
            'values' => 'values('.$grammar->wrap($column).')',
            'alias' => $grammar->wrap('laravel_upsert_alias').'.'.$grammar->wrap($column),
            default => $grammar->wrap('excluded').'.'.$grammar->wrap($column),
        };

        return "case when {$stored('source')} = 'manual' and {$incoming('source')} <> 'manual' "
            ."then {$stored($this->column)} else {$incoming($this->column)} end";
    }
}
