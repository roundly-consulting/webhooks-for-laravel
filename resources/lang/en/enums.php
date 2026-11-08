<?php

declare(strict_types=1);

return [
    'inbound_status' => [
        'received' => 'Received',
        'queued' => 'Queued',
        'processing' => 'Processing',
        'processed' => 'Processed',
        'failed' => 'Failed',
    ],
    'outbound_status' => [
        'pending' => 'Pending',
        'sending' => 'Sending',
        'retrying' => 'Retrying',
        'succeeded' => 'Succeeded',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],
    'duplicate_response' => [
        'accept' => 'Accept (200)',
        'conflict' => 'Conflict (409)',
    ],
    'secret_slot' => [
        'current' => 'Current secret',
        'previous' => 'Previous secret',
    ],
    'secret_encoding' => [
        'standard_webhooks' => 'Standard Webhooks (whsec_)',
        'raw' => 'Raw bytes',
    ],
    'disable_reason' => [
        'consecutive_failures' => 'Consecutive failures',
        'gone' => 'Gone (410)',
        'manual' => 'Disabled by hand',
    ],
    'direction' => [
        'inbound' => 'Inbound',
        'outbound' => 'Outbound',
    ],
    'attempt_error' => [
        'http_status' => 'Error status',
        'redirect_not_followed' => 'Redirect not followed',
        'timeout' => 'Timed out',
        'connection' => 'Connection failed',
        'tls' => 'TLS failed',
        'unresolved' => 'Host not resolved',
        'blocked' => 'Destination blocked',
        'cannot_pin' => 'Destination could not be pinned',
        'too_many_redirects' => 'Too many redirects',
        'redirect_downgrade' => 'Redirect to http refused',
        'signing' => 'Could not sign',
        'worker_lost' => 'Worker lost',
        'unexpected' => 'Unexpected error',
    ],
    'rejection_reason' => [
        'not_found' => 'Not found',
        'endpoint_disabled' => 'Endpoint disabled',
        'rate_limited' => 'Rate limited',
        'payload_too_large' => 'Payload too large',
        'unsupported_content_type' => 'Unsupported content type',
        'missing_signature' => 'Missing signature',
        'invalid_signature' => 'Invalid signature',
        'missing_timestamp' => 'Missing timestamp',
        'invalid_timestamp' => 'Invalid timestamp',
        'stale_timestamp' => 'Stale timestamp',
        'missing_id' => 'Missing delivery id',
        'key_not_bound' => 'Key not bound to the endpoint',
        'unknown_scheme' => 'Unknown scheme',
        'replayed' => 'Replayed',
        'invalid_payload' => 'Invalid payload',
        'idempotency_key_reused' => 'Delivery id reused',
        'in_progress' => 'In progress',
        'conflict' => 'Duplicate',
        'sanitizer_failed' => 'Sanitizer failed',
    ],
];
