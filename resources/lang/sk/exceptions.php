<?php

declare(strict_types=1);

return [
    'endpoint_not_found' => 'Webhookový endpoint sa nenašiel.',
    'endpoint_disabled' => 'Webhookový endpoint je vypnutý.',
    'endpoint_not_subscribed' => 'Webhookový endpoint neodoberá udalosť [:type].',
    'invalid_url' => 'URL webhooku nie je platná.',
    'invalid_header' => 'Vlastná hlavička webhooku nie je povolená (:why).',
    'invalid_event_types' => 'Odbery udalostí nie sú platné.',
    'invalid_secret' => 'Tajný kľúč webhooku nie je platný.',
    'invalid_option' => 'Nastavenie endpointu [:option] nie je platné.',
    'event_type_malformed' => 'Typ udalosti [:type] nie je platný.',
    'event_type_unknown' => 'Typ udalosti [:type] nepatrí medzi nakonfigurované typy.',
    'payload_too_large' => 'Obsah webhooku má :bytes bajtov; limit je :limit.',
    'payload_pruned' => 'Uložený obsah bol odstránený podľa pravidiel uchovávania.',
    'delivery_in_progress' => 'Doručenie ešte prebieha.',
    'destination_blocked' => 'URL webhooku nemožno použiť.',
    'signature_rejected' => 'Podpis webhooku bol odmietnutý (:reason).',
    'unknown_scheme' => 'Podpisová schéma [:name] nie je zaregistrovaná.',
    'not_a_scheme' => 'Podpisová schéma [:name] neimplementuje rozhranie schémy.',
    'scheme_cannot_sign' => 'Podpisová schéma [:name] nevie podpisovať odchádzajúce webhooky.',
    'unknown_handler' => 'Obsluha webhooku [:alias] nie je zaregistrovaná.',
    'not_a_handler' => 'Obsluha webhooku [:alias] neimplementuje rozhranie obsluhy.',
    'grace_too_long' => 'Prechodné obdobie môže trvať najviac :seconds sekúnd.',
    'grace_in_the_past' => 'Prechodné obdobie nemôže skončiť v minulosti.',
    'not_an_implementation' => 'Konfiguračná hodnota [:key] musí určovať triedu, ktorá implementuje :contract.',
    'key_type_mismatch' => 'Kľúč modelu :owner nezodpovedá nakonfigurovanému typu kľúča [:type] (webhooks.key_type).',
    'sanitizer_failed' => 'Čistenie obsahu zlyhalo.',
    'signing_unavailable' => 'Endpoint nemožno podpísať.',
];
