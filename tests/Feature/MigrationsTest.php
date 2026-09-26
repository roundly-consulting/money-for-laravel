<?php

declare(strict_types=1);

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\Money\MoneyServiceProvider;
use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;

$migrations = __DIR__.'/../../database/migrations';

it('publishes its single migration timestamped and auto-loads nothing', function (): void {
    expect(MoneyServiceProvider::class)
        ->toNotAutoLoadMigrations()
        ->toPublishMigrationsTimestamped('money-migrations', 1);
});

it('applies on postgres', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('pgsql', migrations: 1);
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('pgsql'), 'no postgres reachable');

it('applies on mysql', function () use ($migrations): void {
    expect($migrations)->toApplyOnConnection('mysql', migrations: 1);
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('mysql'), 'no mysql reachable');

it('publishes translations and config under the money tags', function (): void {
    expect(ServiceProvider::pathsToPublish(MoneyServiceProvider::class, 'money-translations'))->toHaveCount(1)
        ->and(ServiceProvider::pathsToPublish(MoneyServiceProvider::class, 'money-config'))->toHaveCount(1);
});
