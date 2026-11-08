<?php

declare(strict_types=1);

return [
    'inbound_status' => [
        'received' => 'Prijaté',
        'queued' => 'Vo fronte',
        'processing' => 'Spracúva sa',
        'processed' => 'Spracované',
        'failed' => 'Zlyhalo',
    ],
    'outbound_status' => [
        'pending' => 'Čaká',
        'sending' => 'Odosiela sa',
        'retrying' => 'Opakuje sa',
        'succeeded' => 'Doručené',
        'failed' => 'Zlyhalo',
        'cancelled' => 'Zrušené',
    ],
    'duplicate_response' => [
        'accept' => 'Prijať (200)',
        'conflict' => 'Konflikt (409)',
    ],
    'secret_slot' => [
        'current' => 'Aktuálny kľúč',
        'previous' => 'Predchádzajúci kľúč',
    ],
    'secret_encoding' => [
        'standard_webhooks' => 'Standard Webhooks (whsec_)',
        'raw' => 'Surové bajty',
    ],
    'disable_reason' => [
        'consecutive_failures' => 'Opakované zlyhania',
        'gone' => 'Zaniknutý (410)',
        'manual' => 'Vypnutý ručne',
    ],
    'direction' => [
        'inbound' => 'Prichádzajúci',
        'outbound' => 'Odchádzajúci',
    ],
    'attempt_error' => [
        'http_status' => 'Chybový stav',
        'redirect_not_followed' => 'Presmerovanie nenasledované',
        'timeout' => 'Vypršal čas',
        'connection' => 'Spojenie zlyhalo',
        'tls' => 'TLS zlyhalo',
        'unresolved' => 'Hostiteľ sa nepreložil',
        'blocked' => 'Cieľ je zablokovaný',
        'cannot_pin' => 'Cieľ nemožno pripnúť',
        'too_many_redirects' => 'Príliš veľa presmerovaní',
        'redirect_downgrade' => 'Presmerovanie na http odmietnuté',
        'signing' => 'Nemožno podpísať',
        'worker_lost' => 'Worker sa stratil',
        'unexpected' => 'Neočakávaná chyba',
    ],
    'rejection_reason' => [
        'not_found' => 'Nenájdené',
        'endpoint_disabled' => 'Endpoint je vypnutý',
        'rate_limited' => 'Prekročený limit',
        'payload_too_large' => 'Príliš veľký obsah',
        'unsupported_content_type' => 'Nepodporovaný typ obsahu',
        'missing_signature' => 'Chýba podpis',
        'invalid_signature' => 'Neplatný podpis',
        'missing_timestamp' => 'Chýba časová pečiatka',
        'invalid_timestamp' => 'Neplatná časová pečiatka',
        'stale_timestamp' => 'Zastaraná časová pečiatka',
        'missing_id' => 'Chýba identifikátor doručenia',
        'key_not_bound' => 'Kľúč nepatrí endpointu',
        'unknown_scheme' => 'Neznáma schéma',
        'replayed' => 'Opakovaná požiadavka',
        'invalid_payload' => 'Neplatný obsah',
        'idempotency_key_reused' => 'Identifikátor doručenia použitý znova',
        'in_progress' => 'Prebieha',
        'conflict' => 'Duplicita',
        'sanitizer_failed' => 'Čistenie zlyhalo',
    ],
];
