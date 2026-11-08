<?php

declare(strict_types=1);

return [
    'not_found' => 'Nenájdené.',
    'rate_limited' => 'Príliš veľa požiadaviek.',
    'payload_too_large' => 'Obsah je príliš veľký.',
    'unsupported_content_type' => 'Typ obsahu nie je podporovaný.',
    'missing_signature' => 'Chýba podpis.',
    'invalid_signature' => 'Podpis nie je platný.',
    'missing_timestamp' => 'Chýba časová pečiatka.',
    'invalid_timestamp' => 'Časová pečiatka nie je platná.',
    'stale_timestamp' => 'Časová pečiatka je mimo povolenej tolerancie.',
    'missing_id' => 'Chýba identifikátor doručenia.',
    'key_not_bound' => 'Podpisový kľúč nepatrí tomuto endpointu.',
    'unknown_scheme' => 'Podpis nemožno overiť.',
    'replayed' => 'Požiadavka už bola použitá.',
    'invalid_payload' => 'Obsah nie je platný JSON.',
    'idempotency_key_reused' => 'Identifikátor doručenia už bol použitý s iným obsahom.',
    'in_progress' => 'Doručenie s týmto identifikátorom sa ešte ukladá.',
    'conflict' => 'Doručenie už bolo prijaté.',
    'sanitizer_failed' => 'Doručenie nebolo možné uložiť.',
];
