<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Assertions\Migrations\MigrationRunner;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\WebhooksServiceProvider;

it('publishes its migrations instead of auto-loading them', function (): void {
    expect(WebhooksServiceProvider::class)
        ->toNotAutoLoadMigrations()
        ->toPublishMigrationsTimestamped('webhooks-migrations', 5);
});

/**
 * Three FK edges: inbound deliveries → endpoints, outbound deliveries → endpoints, attempts →
 * outbound deliveries. Table names are configurable, so each expression maps to its default.
 */
it('runs its migrations in an order every foreign key can follow', function (): void {
    expect(__DIR__.'/../../database/migrations')->toHaveRunnableMigrationOrder(foreignKeys: 3, tableResolvers: [
        'WebhooksConfig::inboundEndpointsTable()' => 'webhook_inbound_endpoints',
        'WebhooksConfig::inboundDeliveriesTable()' => 'webhook_inbound_deliveries',
        'WebhooksConfig::outboundEndpointsTable()' => 'webhook_outbound_endpoints',
        'WebhooksConfig::outboundDeliveriesTable()' => 'webhook_outbound_deliveries',
        'WebhooksConfig::outboundAttemptsTable()' => 'webhook_outbound_attempts',
    ]);
});

it('applies on postgres and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('pgsql', 5)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'pgsql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('pgsql'), 'no PostgreSQL connection');

it('applies on mysql and refuses a broken order there', function (): void {
    expect(__DIR__.'/../../database/migrations')
        ->toApplyOnConnection('mysql', 5)
        ->toRejectBrokenOrderOnConnection(static fn (array $files): array => array_reverse($files), 'mysql');
})->skip(fn (): bool => ! MigrationRunner::connectionIsAvailable('mysql'), 'no MySQL connection');

it('declares every model swappable through its config key', function (): void {
    expect(InboundEndpoint::class)->toBeSwappableVia('webhooks.models.inbound_endpoint')
        ->and(InboundDelivery::class)->toBeSwappableVia('webhooks.models.inbound_delivery')
        ->and(OutboundEndpoint::class)->toBeSwappableVia('webhooks.models.outbound_endpoint')
        ->and(OutboundDelivery::class)->toBeSwappableVia('webhooks.models.outbound_delivery')
        ->and(OutboundAttempt::class)->toBeSwappableVia('webhooks.models.outbound_attempt');
});
