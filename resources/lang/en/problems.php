<?php

declare(strict_types=1);

return [
    'not_found' => 'Not found.',
    'rate_limited' => 'Too many requests.',
    'payload_too_large' => 'The payload is too large.',
    'unsupported_content_type' => 'The content type is not supported.',
    'missing_signature' => 'The signature is missing.',
    'invalid_signature' => 'The signature is not valid.',
    'missing_timestamp' => 'The timestamp is missing.',
    'invalid_timestamp' => 'The timestamp is not valid.',
    'stale_timestamp' => 'The timestamp is outside the allowed tolerance.',
    'missing_id' => 'The delivery id is missing.',
    'key_not_bound' => 'The signing key does not belong to this endpoint.',
    'unknown_scheme' => 'The signature cannot be verified.',
    'replayed' => 'The request was already used.',
    'invalid_payload' => 'The payload is not valid JSON.',
    'idempotency_key_reused' => 'The delivery id was already used with a different payload.',
    'in_progress' => 'A delivery with this id is still being stored.',
    'conflict' => 'The delivery was already received.',
    'sanitizer_failed' => 'The delivery could not be stored.',
];
