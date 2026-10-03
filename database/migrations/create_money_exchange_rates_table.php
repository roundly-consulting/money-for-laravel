<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Money\Support\MoneyConfig;
use RoundlyConsulting\Money\Support\Schema as MoneySchema;

return new class extends Migration
{
    public function up(): void
    {
        $length = MoneySchema::currencyLength();

        Schema::create(MoneyConfig::table(), function (Blueprint $table) use ($length): void {
            $table->id();
            $table->string('base_currency', $length);
            $table->string('quote_currency', $length);
            // A canonical decimal string: exact on every engine (SQLite would store a
            // fractional NUMERIC as a binary float), and rates are never summed in SQL.
            $table->string('rate', 40);
            $table->date('effective_date');
            $table->string('source', 32)->default('manual');
            $table->timestamps();

            $table->unique(['base_currency', 'quote_currency', 'effective_date'], 'money_rates_pair_date_unique');
            $table->index(['quote_currency', 'effective_date'], 'money_rates_quote_date_index');
        });
    }
};
