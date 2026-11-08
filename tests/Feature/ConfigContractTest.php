<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;

it('ships exactly the config keys it reads', function (): void {
    // `schemes` is a name => class map a host extends (like database.connections): it is read
    // wholesale and every entry is resolved, so its entries are data, not settings to prove one by one.
    expect(__DIR__.'/../../config/webhooks.php')->toSatisfyConfigContract(__DIR__.'/../../src', ['allowUnread' => ['webhooks.schemes.default', 'webhooks.schemes.rfc9421']]);
});

it('never claims a config handle Laravel ships itself', function (): void {
    $framework = dirname((string) (new ReflectionClass(Application::class))->getFileName(), 4);

    expect($framework.'/config/app.php')->toBeFile()
        ->and($framework.'/config/webhooks.php')->not->toBeFile();
});
