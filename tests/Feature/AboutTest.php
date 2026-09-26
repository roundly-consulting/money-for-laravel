<?php

declare(strict_types=1);

it('renders the money about section', function (): void {
    expect('money')->toLeakNoSecrets(secrets: [], mustRender: ['Default currency', 'EUR', 'Currencies', 'ISO 165 / custom 0']);
});
