<?php

declare(strict_types=1);

it('installs the config and both sets of migrations into the sandbox', function (): void {
    $this->artisan('webhooks:install')->expectsOutputToContain('queue worker')->assertSuccessful();

    $migrations = array_map(basename(...), glob($this->sandbox.'/database/migrations/*.php') ?: []);

    expect($this->sandbox.'/config/webhooks.php')->toBeFile()
        ->and(config_path('webhooks.php'))->toStartWith($this->sandbox)
        ->and(array_filter($migrations, fn (string $file): bool => str_contains($file, 'create_webhook_')))->toHaveCount(5)
        ->and(array_filter($migrations, fn (string $file): bool => str_contains($file, 'sentinel_idempotency_keys')))->toHaveCount(1);
});
