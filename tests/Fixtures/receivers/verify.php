<?php

declare(strict_types=1);

function verifyWebhook(string $secret, string $id, string $timestamp, string $signature, string $body, ?int $now = null): bool
{
    // "whsec_" + base64 → the decoded bytes; any other secret is used as its own bytes.
    $key = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : $secret;

    if ($key === false || preg_match('/^\d{1,12}$/', $timestamp) !== 1 || abs(($now ?? time()) - (int) $timestamp) > 300) {
        return false;
    }

    $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));

    // Several "v1,<base64>" entries during a secret rotation: any one may match.
    foreach (explode(' ', $signature) as $entry) {
        [$version, $mac] = array_pad(explode(',', $entry, 2), 2, '');

        if ($version === 'v1' && hash_equals($expected, $mac)) {
            return true;
        }
    }

    return false;
}
