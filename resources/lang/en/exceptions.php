<?php

declare(strict_types=1);

return [
    'endpoint_not_found' => 'The webhook endpoint was not found.',
    'endpoint_disabled' => 'The webhook endpoint is disabled.',
    'endpoint_not_subscribed' => 'The webhook endpoint is not subscribed to [:type].',
    'invalid_url' => 'The webhook URL is not valid.',
    'invalid_header' => 'A custom webhook header is not allowed (:why).',
    'invalid_event_types' => 'The event subscriptions are not valid.',
    'invalid_secret' => 'The webhook secret is not valid.',
    'invalid_option' => 'The endpoint option [:option] is not valid.',
    'event_type_malformed' => 'The event type [:type] is not valid.',
    'event_type_unknown' => 'The event type [:type] is not one of the configured types.',
    'payload_too_large' => 'The webhook payload is :bytes bytes; the limit is :limit.',
    'payload_pruned' => 'The stored payload was removed by retention.',
    'delivery_in_progress' => 'The delivery is still in progress.',
    'destination_blocked' => 'The webhook URL cannot be used.',
    'signature_rejected' => 'The webhook signature was rejected (:reason).',
    'unknown_scheme' => 'The signature scheme [:name] is not registered.',
    'not_a_scheme' => 'The signature scheme [:name] does not implement the scheme contract.',
    'unknown_handler' => 'The webhook handler [:alias] is not registered.',
    'not_a_handler' => 'The webhook handler [:alias] does not implement the handler contract.',
    'grace_too_long' => 'The grace period may be at most :seconds seconds.',
    'grace_in_the_past' => 'The grace period cannot end in the past.',
    'not_an_implementation' => 'The configuration value [:key] must name a class implementing :contract.',
    'key_type_mismatch' => 'The :owner key does not fit the configured key type [:type] (webhooks.key_type).',
    'sanitizer_failed' => 'The payload sanitizer failed.',
    'signing_unavailable' => 'The endpoint cannot be signed.',
];
