<?php

declare(strict_types=1);

use RoundlyConsulting\Testing\Arch\ArchPresets;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Models\InboundEndpoint;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Models\OutboundDelivery;
use RoundlyConsulting\Webhooks\Models\OutboundEndpoint;
use RoundlyConsulting\Webhooks\WebhooksManager;

ArchPresets::strictTypes('RoundlyConsulting\Webhooks');

// The manager is non-final so the fake can extend it; the five models are swappable.
ArchPresets::finalByDefault('RoundlyConsulting\Webhooks', [
    WebhooksManager::class,
    InboundEndpoint::class,
    InboundDelivery::class,
    OutboundEndpoint::class,
    OutboundDelivery::class,
    OutboundAttempt::class,
]);

ArchPresets::swappableModelsAreNotFinal([
    InboundEndpoint::class => 'webhooks.models.inbound_endpoint',
    InboundDelivery::class => 'webhooks.models.inbound_delivery',
    OutboundEndpoint::class => 'webhooks.models.outbound_endpoint',
    OutboundDelivery::class => 'webhooks.models.outbound_delivery',
    OutboundAttempt::class => 'webhooks.models.outbound_attempt',
]);

ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\Webhooks');

ArchPresets::modelsResolveThroughSeam(__DIR__.'/../../src', 'Support', [
    'webhooks.models.inbound_endpoint',
    'webhooks.models.inbound_delivery',
    'webhooks.models.outbound_endpoint',
    'webhooks.models.outbound_delivery',
    'webhooks.models.outbound_attempt',
]);

ArchPresets::factoriesResolveThroughSeam(__DIR__.'/../../database/factories', __DIR__.'/../../src');

ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

// The Dependency Policy as a test: php/ext, illuminate/* and roundly packages only — no alsoAllow.
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers();

ArchPresets::modelsGoThroughTheFacade('RoundlyConsulting\Webhooks');

/**
 * Every PHP token of every file under src/ — a scan the arch DSL cannot do (language constructs,
 * calls through facades).
 *
 * @return array<string, list<array{0: int, 1: string, 2: int}|string>>
 */
function sourceTokens(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = token_get_all((string) file_get_contents($file->getPathname()));
        }
    }

    return $files;
}

/**
 * Where a function (or language construct) named `$names` is called in src/.
 *
 * @param  list<string>  $names
 * @return list<string>
 */
function callsTo(array $names): array
{
    $found = [];

    foreach (sourceTokens() as $path => $tokens) {
        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            $name = strtolower($token[1]);
            $construct = $token[0] === T_EVAL && in_array('eval', $names, true);
            $call = $token[0] === T_STRING && in_array($name, $names, true);

            if (! $construct && ! $call) {
                continue;
            }

            $next = $tokens[$i + 1] ?? null;
            $next = is_array($next) && $next[0] === T_WHITESPACE ? ($tokens[$i + 2] ?? null) : $next;
            $previous = $tokens[$i - 1] ?? null;
            $isMethod = is_array($previous) && in_array($previous[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_FUNCTION], true);

            if ($next === '(' && ! $isMethod || $construct) {
                $found[] = basename($path).':'.$token[2].' '.$name;
            }
        }
    }

    return $found;
}

// C-55 — payloads are untrusted input: never deserialized into objects, never evaluated, never
// spread into variables. (Proved red by a throwaway `unserialize($body)` in ReceiveWebhookAction.)
it('no_unserialize_eval_or_extract', function (): void {
    expect(callsTo(['unserialize', 'eval', 'extract']))->toBe([]);
});

// C-88 — every MAC goes through sentinel (Sentinel::macs()), never crypto's Hmac directly.
// (Proved red by a throwaway `Crypto::hmac()->sign(...)` in MacEngine.)
arch('every_mac_goes_through_sentinel')
    ->expect('RoundlyConsulting\Webhooks')
    ->not->toUse(['RoundlyConsulting\Crypto\Hash\Hmac', 'RoundlyConsulting\Crypto\Signature\Hs']);

it('calls no hmac function of its own', function (): void {
    expect(callsTo(['hmac', 'hash_hmac', 'signwithsecret', 'verifywithsecrets']))->toBe([]);
});
