<?php

// CI gate: the security core must be covered line for line (plan OQ-17: 95 % overall, 100 % on
// the signature schemes, inbound verification and the SSRF path).
//
//   php bin/core-coverage.php build/clover.xml

declare(strict_types=1);

$core = [
    'src/Schemes/DefaultScheme.php',
    'src/Schemes/Rfc9421Scheme.php',
    'src/Schemes/EndpointSecrets.php',
    'src/Support/MacEngine.php',
    'src/Actions/Inbound/ReceiveWebhookAction.php',
    'src/Actions/Inbound/VerifyInboundRequestAction.php',
    'src/Support/DestinationPolicyFactory.php',
    'src/Support/OutboundUrls.php',
    'src/Actions/Outbound/AttemptDeliveryAction.php',
];

$clover = simplexml_load_file($argv[1] ?? 'build/clover.xml');

if ($clover === false) {
    fwrite(STDERR, "No clover report.\n");
    exit(1);
}

$root = realpath(__DIR__.'/..').'/';
$failed = false;
$seen = [];

foreach ($clover->xpath('//file') ?: [] as $file) {
    $path = str_replace($root, '', (string) $file['name']);

    if (! in_array($path, $core, true)) {
        continue;
    }

    $seen[] = $path;
    $missed = [];

    foreach ($file->line as $line) {
        if ((string) $line['type'] === 'stmt' && (int) $line['count'] === 0) {
            $missed[] = (int) $line['num'];
        }
    }

    if ($missed !== []) {
        $failed = true;
        fwrite(STDERR, "{$path}: lines ".implode(', ', $missed)." not covered\n");
    } else {
        echo "{$path}: 100 %\n";
    }
}

foreach (array_diff($core, $seen) as $path) {
    $failed = true;
    fwrite(STDERR, "{$path}: not in the coverage report\n");
}

exit($failed ? 1 : 0);
