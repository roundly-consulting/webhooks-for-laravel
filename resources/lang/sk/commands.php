<?php

declare(strict_types=1);

return [
    'installed' => 'Webhooky sú nainštalované.',
    'next_migrate' => 'Spustite `php artisan migrate`.',
    'next_queue' => 'Spustite worker frontu — doručenia sa spracúvajú iba vo fronte.',
    'next_schedule' => 'Spustite plánovač (webhooks:retry každú minútu, webhooks:prune denne).',
    'doctor_ok' => 'Nenašli sa žiadne problémy.',
    'doctor' => [
        'config' => 'Konfigurácia webhookov nie je platná.',
        'sync_queue' => 'Spojenie fronty je sync: doručenia by sa spracúvali počas požiadavky.',
        'rate_limit_store' => 'Úložisko limitov je v pamäti: obmedzenie na endpoint platí len v rámci procesu. Použite zdieľané úložisko.',
        'retry_after' => 'Hodnota retry_after spojenia fronty nie je dlhšia ako časový limit úlohy doručenia.',
        'curl' => 'Na pripnutie odchádzajúcich spojení je potrebné ext-curl s CURLOPT_RESOLVE.',
        'ssrf_disabled' => 'Ochrana proti SSRF je vypnutá.',
        'http_in_production' => 'Odchádzajúce http je v produkcii povolené.',
        'idempotency_table' => 'Chýba tabuľka idempotencie zo sentinelu: zverejnite a spustite migrácie sentinelu.',
        'tables' => 'Chýbajú tabuľky webhookov: spustite migrácie.',
        'unknown_scheme' => 'Endpoint používa neznámu podpisovú schému.',
        'unknown_handler' => 'Endpoint používa neznámu obsluhu.',
        'rfc9421_id_unsigned' => 'Profil endpointu RFC 9421 nepokrýva hlavičku s identifikátorom doručenia.',
    ],
    'pruned' => 'Vyčistených záznamov: :count.',
    'pruned_dry' => 'Vyčistilo by sa záznamov: :count.',
    'dispatched' => 'Odoslaných úloh doručenia: :count.',
    'rotated' => 'Tajný kľúč bol vymenený. Predchádzajúci platí do :until. Nový kľúč (zobrazí sa iba raz):',
    'invalid_arguments' => 'Zadajte smer (inbound alebo outbound), identifikátor endpointu a voliteľne prechodné obdobie v sekundách.',
    'delivery_not_found' => 'Doručenie sa nenašlo.',
    'redelivered' => 'Doručenie bolo znova zaradené do fronty.',
    'replayed' => 'Doručenie bolo zaradené na opakované spracovanie.',
];
