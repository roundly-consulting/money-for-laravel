<?php

declare(strict_types=1);

it('renders the money about section without provider config', function (): void {
    expect('money')->toLeakNoSecrets(
        secrets: ['sk_test_money_dummy_7f3a'],
        mustRender: ['Default currency', 'EUR', 'Currencies', 'ISO 165 / custom 0', 'Formatter', 'Exchange driver', 'ecb', 'Rates schedule', 'off'],
    );
});

it('shows the schedule when enabled', function (): void {
    config(['money.exchange.refresh.schedule' => true]);

    expect('money')->toLeakNoSecrets(secrets: ['sk_test_money_dummy_7f3a'], mustRender: ['30 16 * * 1-5 (Europe/Berlin)']);
});
