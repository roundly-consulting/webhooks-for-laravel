<?php

declare(strict_types=1);

use RoundlyConsulting\Webhooks\Http\Resources\WebhookOwnerResource;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\Schemes\DefaultScheme;
use RoundlyConsulting\Webhooks\Schemes\Rfc9421Scheme;
use RoundlyConsulting\Webhooks\Support\KeyRedactingSanitizer;

/*
|--------------------------------------------------------------------------
| Webhooks
|--------------------------------------------------------------------------
|
| Every value is read through strict readers: a typo throws naming the key,
| and a blank value (a host's `KEY=`) means "not set", so the default applies.
| Env values are passed through raw — never `(bool) env()` or `(int) env()`.
|
*/

return [

    /*
    | The key type of the host models that own endpoints (the `owner` morph on
    | both endpoint tables): bigint, uuid or ulid. Set it before migrating.
    */
    'key_type' => env('WEBHOOKS_KEY_TYPE', 'bigint'),

    /*
    | The package's own primary keys (and the foreign keys between its tables):
    | bigint, uuid or ulid. Set it before migrating. Wire ids are ULIDs anyway.
    */
    'primary_key_type' => env('WEBHOOKS_PRIMARY_KEY_TYPE', 'bigint'),

    /*
    | Swappable models. A replacement must extend the packaged model.
    */
    'models' => [
        'inbound_endpoint' => InboundEndpoint::class,
        'inbound_delivery' => InboundDelivery::class,
        'outbound_endpoint' => OutboundEndpoint::class,
        'outbound_delivery' => OutboundDelivery::class,
        'outbound_attempt' => OutboundAttempt::class,
    ],

    'tables' => [
        'inbound_endpoints' => 'webhook_inbound_endpoints',
        'inbound_deliveries' => 'webhook_inbound_deliveries',
        'outbound_endpoints' => 'webhook_outbound_endpoints',
        'outbound_deliveries' => 'webhook_outbound_deliveries',
        'outbound_attempts' => 'webhook_outbound_attempts',
    ],

    'resources' => [
        // How an endpoint's owner renders in the JSON resources. Point it at your own
        // JsonResource to add a name or an avatar; it receives the owner model. The default
        // renders only {id, type} with the class basename — never a full class name.
        'owner' => WebhookOwnerResource::class,
    ],

    /*
    | Queues. Processing always happens on a queue — never inside the request.
    | Blank inbound/outbound names use `queue.queue`; blank `queue.queue` uses the
    | connection's default queue. Retry delays longer than `max_delay` seconds
    | (SQS' DelaySeconds limit is 900) wait for the `webhooks:retry` sweep.
    */
    'queue' => [
        'connection' => env('WEBHOOKS_QUEUE_CONNECTION'),
        'queue' => env('WEBHOOKS_QUEUE_NAME'),
        'inbound' => env('WEBHOOKS_INBOUND_QUEUE'),
        'outbound' => env('WEBHOOKS_OUTBOUND_QUEUE'),
        'max_delay' => 900,
    ],

    'inbound' => [
        'route' => [
            // POST {prefix}/{key}. Off: mount your own route calling Webhooks::inbound()->receive().
            'enabled' => env('WEBHOOKS_INBOUND_ROUTE', true),
            'prefix' => 'webhooks',
            'domain' => null,
            // Never `web`: a sender has no session or CSRF token.
            'middleware' => [],
            // The base the endpoint URL is built on (e.g. behind a proxy); blank = route().
            'url_base' => env('WEBHOOKS_INBOUND_URL'),
        ],
        // Seconds a signed timestamp may be away from now, past or future.
        'tolerance' => 300,
        'max_payload_bytes' => 262144,
        // No endpoint override may raise the limit beyond this.
        'max_payload_bytes_ceiling' => 1048576,
        'content_types' => ['application/json', 'application/*+json'],
        // A delivery seen before: accept (200, no new event) or conflict (409).
        'on_duplicate' => 'accept',
        // Use an `Idempotency-Key` header when the scheme carries no delivery id.
        'accept_idempotency_header' => true,
        // How long a delivery id is remembered (seconds).
        'dedupe_ttl' => 604800,
        'rate_limit' => [
            // Verified deliveries per endpoint per minute.
            'per_minute' => 600,
            // Rejected requests per endpoint and IP per minute, before any signature work.
            'rejected_per_minute' => 60,
            // The cache store the limiters use; blank = the default store.
            'cache_store' => null,
        ],
        // Request headers stored with a delivery — never signatures, cookies or credentials.
        'store_headers' => ['content-type', 'user-agent', 'webhook-id', 'webhook-timestamp'],
        // A stored delivery not queued after this many seconds is queued by the sweep.
        'requeue_after' => 60,
        'handler' => [
            'tries' => 3,
            'backoff' => [10, 60, 300],
            'timeout' => 60,
        ],
    ],

    'outbound' => [
        // http as well as https — local development only.
        'allow_http' => env('WEBHOOKS_ALLOW_HTTP', false),
        // Resolve and vet the URL when an endpoint is saved (else every rule but the lookup).
        'verify_on_save' => true,
        // Wrap payloads in {"type", "timestamp", "data"}.
        'envelope' => true,
        // Known event types; empty = any valid name.
        'event_types' => [],
        'max_payload_bytes' => 262144,
        // How much of a receiver's answer is read and stored.
        'max_response_bytes' => 4096,
        'timeouts' => [
            'connect' => 5,
            'total' => 10,
        ],
        'max_attempts' => 8,
        'retry' => [
            // delay(n) = min(base * multiplier^(n-1), cap) seconds, then ± jitter_permille.
            'base' => 60,
            'multiplier' => 4,
            'cap' => 32400,
            'jitter_permille' => 100,
            // The longest Retry-After honoured (seconds).
            'max_retry_after' => 86400,
            // An explicit list of delays (seconds) instead of the formula, e.g. [60, 300, 3600].
            'schedule' => null,
        ],
        'rate_limit' => [
            // Requests per endpoint per minute (http-client-rate-limits; its store must be shared).
            'per_minute' => 60,
        ],
        // A due delivery whose queued job is this many seconds late is queued again by the sweep.
        'requeue_after' => 120,
        // Failed deliveries in a row before an endpoint is disabled.
        'disable_after' => 5,
        // A 410 Gone answer disables the endpoint at once.
        'disable_on_gone' => true,
        // Follow 307/308 redirects (each hop vetted again).
        'follow_redirects' => false,
        'max_redirects' => 3,
        'headers' => [
            // Reserved custom headers an endpoint may still set, e.g. ['authorization'].
            'allowed_overrides' => [],
        ],
        // The User-Agent your receivers see. Blank = "{app.name} Webhooks".
        'user_agent' => env('WEBHOOKS_USER_AGENT'),
    ],

    /*
    | Outbound destination safety (safe-fetch-for-laravel). The host's own
    | safe-fetch blocks apply too; its carve-outs (private hosts, allowed ranges,
    | a host allowlist) do not — tenant endpoints get only what is listed here.
    */
    'ssrf' => [
        // Off only behind a filtering egress proxy you trust (webhooks:doctor warns).
        'enabled' => env('WEBHOOKS_SSRF_GUARD', true),
        'block_app_hosts' => true,
        'app_hosts' => [],
        // Non-empty: ONLY these hosts (exact or *.example.com).
        'allowed_hosts' => [],
        'blocked_hosts' => [],
        // Addresses / CIDRs exempt from the private-network table, e.g. ['127.0.0.1/32'] locally.
        'allowed_ranges' => [],
        'blocked_ranges' => [],
        // null = any port.
        'allowed_ports' => null,
    ],

    'secrets' => [
        // Random bytes in a generated secret (whsec_ + base64).
        'bytes' => 32,
        // How long a rotated-out secret keeps verifying/signing by default (seconds).
        'grace_seconds' => 86400,
        'max_grace_seconds' => 604800,
    ],

    /*
    | Signature schemes by name. Add your own (e.g. a GitHub-style verifier):
    | 'github' => App\Webhooks\GitHubScheme::class.
    */
    'schemes' => [
        'default' => DefaultScheme::class,
        'rfc9421' => Rfc9421Scheme::class,
    ],

    'rfc9421' => [
        // The sentinel signature profile inbound RFC 9421 endpoints verify against.
        'profile' => 'default',
        // The sentinel key id outbound RFC 9421 endpoints sign with (blank = per endpoint only).
        'outbound_key_id' => null,
    ],

    /*
    | Inbound handlers by alias: 'billing' => App\Webhooks\BillingWebhookHandler::class.
    | An endpoint stores the alias, never a class name.
    */
    'handlers' => [],

    'sanitizer' => [
        'class' => KeyRedactingSanitizer::class,
        'redact_keys' => ['password', 'secret', 'token', 'api_key', 'authorization', 'card_number', 'cvc', 'ssn'],
        'replacement' => '[redacted]',
    ],

    'payloads' => [
        // Encrypt stored payloads and bodies at rest.
        'encrypt' => true,
        // Payloads, bodies and response excerpts are cleared after this many days.
        'retention_days' => 30,
        // Delivery and attempt rows are deleted after this many days (null = keep).
        'delivery_retention_days' => 90,
    ],

    'schedule' => [
        'enabled' => true,
        'retry' => 'everyMinute',
        'prune' => 'daily',
    ],

];
