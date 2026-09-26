<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A consumer-style migration: every money macro, called with named arguments exactly as
 * published consumer migrations do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_products', function (Blueprint $table): void {
            $table->id();
            $table->money('price', nullable: true);
            $table->money('compare_at_price', currency: 'currency', nullable: true);
            $table->money('sale_price', currency: 'currency', nullable: true);
            $table->money('budget', currency: false, nullable: true);
            $table->money('store_credit', currency: false, nullable: true);
            $table->money('total', currency: false, nullable: true);
            $table->currencyCode('shop_currency', nullable: true);
            $table->moneyJson('snapshot', nullable: true);
            $table->bigInteger('legacy_amount')->nullable();
            $table->currencyCode('legacy_currency', nullable: true);
            $table->timestamps();
        });
    }
};
