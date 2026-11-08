<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\SafeFetch\Enums\Reason;
use RoundlyConsulting\Webhooks\DataTransferObjects\OutboundEndpointChanges;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Exceptions\DestinationBlockedException;
use RoundlyConsulting\Webhooks\Exceptions\InvalidEndpointException;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Support\DestinationPolicyFactory;

/**
 * Outbound destination safety (safe-fetch) as webhooks wires it: on save and on every attempt.
 */
beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

function blockedReason(Closure $create): DestinationBlockedException
{
    try {
        $create();
    } catch (DestinationBlockedException $exception) {
        return $exception;
    }

    throw new LogicException('Expected the destination to be blocked.');
}

it('an_http_url_is_refused_by_default', function (): void {
    expect(blockedReason(fn () => Webhooks::outbound()->create('http://hooks.example.com/in'))->reason())->toBe(Reason::Scheme);
});

it('http_is_allowed_by_config', function (): void {
    config()->set('webhooks.outbound.allow_http', true);

    $endpoint = Webhooks::outbound()->create('http://hooks.example.com/in')->endpoint;
    Http::fake(['*' => Http::response('ok')]);
    attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    expect($endpoint->url)->toBe('http://hooks.example.com/in');
    Http::assertSent(fn (ClientRequest $request): bool => str_starts_with($request->url(), 'http://hooks.example.com'));
});

it('ambiguous_urls_are_refused', function (string $url): void {
    expect(fn () => Webhooks::outbound()->create($url))->toThrow(DestinationBlockedException::class);
})->with([
    'backslash userinfo' => 'https://a.com\\@127.0.0.1/',
    'fragment trick' => 'https://127.0.0.1#@hooks.example.com/',
    'decimal ip' => 'https://2130706433/',
    'hex ip' => 'https://0x7f.1/',
    'octal ip' => 'https://0177.0.0.1/',
    'short ip' => 'https://127.1/',
    'userinfo' => 'https://user@hooks.example.com/',
    'encoded host' => 'https://hooks%2eexample.com/',
]);

it('the_blocked_network_contract_holds_through_the_policy', function (string $address, bool $blocked): void {
    publicDns(['probe.example.com' => $address]);

    $create = fn () => Webhooks::outbound()->create('https://probe.example.com/in');

    if ($blocked) {
        expect($create)->toThrow(DestinationBlockedException::class);
    } else {
        expect($create()->endpoint->url_host)->toBe('probe.example.com');
    }
})->with([
    'loopback' => ['127.0.0.1', true],
    'rfc1918' => ['172.16.4.2', true],
    'link-local / metadata' => ['169.254.169.254', true],
    'cgnat' => ['100.64.0.9', true],
    'azure wireserver' => ['168.63.129.16', true],
    'ula' => ['fd12:3456::1', true],
    'multicast' => ['224.0.0.1', true],
    'documentation' => ['192.0.2.10', true],
    'ipv4-embedded ipv6' => ['::ffff:10.0.0.1', true],
    'public control' => ['93.184.216.34', false],
    'public v6 control' => ['2606:4700::1111', false],
]);

it('the_apps_own_host_is_blocked', function (string $url): void {
    config()->set('webhooks.ssrf.app_hosts', ['admin.app.test']);
    config()->set('webhooks.ssrf.blocked_hosts', ['*.evil.example']);
    publicDns(['app.test' => '93.184.216.40', 'admin.app.test' => '93.184.216.41', 'x.evil.example' => '93.184.216.42']);

    expect(fn () => Webhooks::outbound()->create($url))->toThrow(DestinationBlockedException::class);
})->with(['https://app.test/in', 'https://admin.app.test/in', 'https://localhost/in', 'https://api.localhost/in', 'https://x.evil.example/in']);

it('lets the app host through when the block is switched off', function (): void {
    config()->set('webhooks.ssrf.block_app_hosts', false);
    publicDns(['app.test' => '93.184.216.40']);

    expect(Webhooks::outbound()->create('https://app.test/in')->endpoint->url_host)->toBe('app.test');
});

it('an_allowed_host_is_still_vetted_and_pinned', function (): void {
    config()->set('webhooks.ssrf.allowed_hosts', ['*.example.com']);
    publicDns(['private.example.com' => '10.0.0.8', 'hooks.example.org' => '93.184.216.50']);

    expect(blockedReason(fn () => Webhooks::outbound()->create('https://hooks.example.org/in'))->reason())->toBe(Reason::NotAllowed)
        ->and(blockedReason(fn () => Webhooks::outbound()->create('https://private.example.com/in'))->reason())->toBe(Reason::Blocked);

    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in')->endpoint;
    Http::fake(fn (ClientRequest $request, array $options) => Http::response(implode(',', $options['curl'][CURLOPT_RESOLVE] ?? [])));
    attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    Http::assertSent(fn (): bool => true);
    expect(OutboundAttempt::query()->sole()->response_body)->toBe('hooks.example.com:443:93.184.216.34');
});

it('a_blocked_range_beats_an_allowed_range', function (): void {
    config()->set('webhooks.ssrf.allowed_ranges', ['10.0.0.0/8']);
    config()->set('webhooks.ssrf.blocked_ranges', ['10.9.0.0/16', '93.184.216.35/32']);
    publicDns(['lan.example.com' => '10.1.2.3', 'blocked.example.com' => '10.9.1.1']);

    expect(Webhooks::outbound()->create('https://lan.example.com/in')->endpoint->url_host)->toBe('lan.example.com')
        ->and(blockedReason(fn () => Webhooks::outbound()->create('https://blocked.example.com/in'))->blockedBy())->toBe('blocked_range')
        ->and(fn () => Webhooks::outbound()->create('https://other.example.com/in'))->toThrow(DestinationBlockedException::class);
});

it('drops the host application\'s own carve-outs for tenant endpoints', function (): void {
    config()->set('safe-fetch.hosts.private', ['minio.internal']);
    config()->set('safe-fetch.ranges.allowed', ['10.0.0.0/8']);
    publicDns(['minio.internal' => '10.0.0.20', 'lan.example.com' => '10.1.2.3']);

    expect(fn () => Webhooks::outbound()->create('https://minio.internal/in'))->toThrow(DestinationBlockedException::class)
        ->and(fn () => Webhooks::outbound()->create('https://lan.example.com/in'))->toThrow(DestinationBlockedException::class);
});

it('a_port_outside_the_allowlist_is_refused', function (): void {
    config()->set('webhooks.ssrf.allowed_ports', [443, 8443]);

    expect(Webhooks::outbound()->create('https://hooks.example.com:8443/in')->endpoint->url)->toBe('https://hooks.example.com:8443/in')
        ->and(blockedReason(fn () => Webhooks::outbound()->create('https://hooks.example.com:9000/in'))->reason())->toBe(Reason::Port);
});

it('every_attempt_is_pinned_to_the_vetted_addresses', function (): void {
    publicDns(['dual.example.com' => ['93.184.216.34', '2606:4700::1111']]);
    $endpoint = outboundEndpoint('https://dual.example.com/in');
    $seen = [];
    Http::fake(function (ClientRequest $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('ok');
    });

    attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    expect($seen['proxy'])->toBe('')
        ->and($seen['allow_redirects'])->toBeFalse()
        ->and($seen['curl'][CURLOPT_RESOLVE])->toBe(['dual.example.com:443:93.184.216.34,[2606:4700::1111]'])
        ->and($seen['curl'][CURLOPT_FRESH_CONNECT])->toBeTrue()
        ->and($seen['curl'][CURLOPT_FORBID_REUSE])->toBeTrue()
        ->and($seen['curl'])->not->toHaveKey(CURLOPT_PROTOCOLS)
        ->and($seen['curl'])->not->toHaveKey(CURLOPT_PROXY);
});

it('dns_is_resolved_once_per_hop', function (): void {
    $dns = publicDns();
    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in')->endpoint;
    $dns->assertLookedUp('hooks.example.com', 1);   // on save
    Http::fake(['*' => Http::response('ok')]);

    attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    $dns->assertLookedUp('hooks.example.com', 2);   // once more for the one hop
});

it('only_method_preserving_redirects_are_followed', function (): void {
    config()->set('webhooks.outbound.follow_redirects', true);
    Http::fake([
        'https://hooks.example.com/in' => Http::response('', 302, ['Location' => 'https://other.example.com/in']),
        'https://hooks.example.com/moved' => Http::response('', 308, ['Location' => 'https://other.example.com/in']),
        'https://other.example.com/*' => Http::response('ok'),
    ]);

    $found = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', []));
    $moved = attempt(Webhooks::send(outboundEndpoint('https://hooks.example.com/moved'), 'invoice.paid', []));

    expect($found->last_error_code)->toBe(AttemptError::RedirectNotFollowed)
        ->and($moved->status)->toBe(OutboundStatus::Succeeded);
    Http::assertSent(fn (ClientRequest $request): bool => $request->url() === 'https://other.example.com/in' && $request->method() === 'POST' && $request->hasHeader('webhook-signature'));
});

it('a_downgrade_redirect_is_refused', function (): void {
    config()->set('webhooks.outbound.follow_redirects', true);
    config()->set('webhooks.outbound.allow_http', true);
    Http::fake(['*' => Http::response('', 307, ['Location' => 'http://other.example.com/in'])]);

    $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', []));

    Http::assertSentCount(1);
    expect($delivery->last_error_code)->toBe(AttemptError::RedirectDowngrade)
        ->and($delivery->status)->toBe(OutboundStatus::Failed);
});

it('too_many_redirects_fail_the_attempt', function (): void {
    config()->set('webhooks.outbound.follow_redirects', true);
    config()->set('webhooks.outbound.max_redirects', 1);
    Http::fake(['*' => Http::response('', 307, ['Location' => 'https://hooks.example.com/in'])]);

    $delivery = attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', []));

    expect($delivery->last_error_code)->toBe(AttemptError::TooManyRedirects);
});

it('custom_headers_never_follow_a_redirect_to_another_origin', function (): void {
    config()->set('webhooks.outbound.follow_redirects', true);
    Http::fake([
        'https://hooks.example.com/*' => Http::response('', 307, ['Location' => 'https://other.example.com/in']),
        'https://other.example.com/*' => Http::response('ok'),
    ]);
    $endpoint = outboundEndpoint(attributes: ['headers' => ['x-tenant-token' => 'abc']]);

    attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'hooks.example.com') && $request->hasHeader('x-tenant-token'));
    Http::assertSent(fn (ClientRequest $request): bool => str_contains($request->url(), 'other.example.com') && ! $request->hasHeader('x-tenant-token') && $request->hasHeader('webhook-id'));
});

it('a_blocked_destination_is_not_retried', function (): void {
    $endpoint = outboundEndpoint();
    publicDns(['hooks.example.com' => '127.0.0.1']);
    Http::fake();

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    expect($delivery->status)->toBe(OutboundStatus::Failed)
        ->and($delivery->last_error_message)->toBe('blocked (private_address)')
        ->and($endpoint->refresh()->consecutive_failures)->toBe(1);
});

it('an_unresolvable_host_is_retried', function (): void {
    $endpoint = outboundEndpoint();
    publicDns(['hooks.example.com' => []]);
    Http::fake();

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    expect($delivery->status)->toBe(OutboundStatus::Retrying)
        ->and($delivery->last_error_code)->toBe(AttemptError::Unresolved);
});

it('refuses an unresolvable URL on save, and checks the shape only without verify_on_save', function (): void {
    publicDns(['nowhere.example.com' => []]);

    $exception = blockedReason(fn () => Webhooks::outbound()->create('https://nowhere.example.com/in'));
    expect($exception->unresolved())->toBeTrue()
        ->and($exception->getMessage())->toBe(blockedReason(fn () => Webhooks::outbound()->create('https://10.0.0.1/'))->getMessage());

    config()->set('webhooks.outbound.verify_on_save', false);
    expect(Webhooks::outbound()->create('https://nowhere.example.com/in')->endpoint->url_host)->toBe('nowhere.example.com')
        ->and(fn () => Webhooks::outbound()->create('https://10.0.0.1/'))->toThrow(DestinationBlockedException::class)
        ->and(fn () => Webhooks::outbound()->create('https://localhost/'))->toThrow(DestinationBlockedException::class);
});

it('judges a changed URL like a new one', function (): void {
    $endpoint = outboundEndpoint();

    expect(fn () => Webhooks::outbound()->update($endpoint, new OutboundEndpointChanges(url: 'https://10.0.0.1/')))->toThrow(DestinationBlockedException::class)
        ->and(fn () => Webhooks::outbound()->create(str_repeat('a', 2049)))->toThrow(InvalidEndpointException::class);
});

it('sends unguarded only when the guard is switched off', function (): void {
    config()->set('webhooks.ssrf.enabled', false);
    $seen = [];
    Http::fake(function (ClientRequest $request, array $options) use (&$seen) {
        $seen = $options;

        return Http::response('ok');
    });

    $endpoint = Webhooks::outbound()->create('https://10.0.0.1/in')->endpoint;
    attempt(Webhooks::send($endpoint, 'invoice.paid', []));

    expect($seen['allow_redirects'])->toBeFalse()->and($seen)->not->toHaveKey('curl')
        ->and(fn () => Webhooks::outbound()->create('ftp://files.example.com/'))->toThrow(InvalidEndpointException::class)
        ->and(fn () => Webhooks::outbound()->create('http://hooks.example.com/'))->toThrow(InvalidEndpointException::class)
        ->and(fn () => Webhooks::outbound()->create('https://user@hooks.example.com/'))->toThrow(InvalidEndpointException::class);
});

it('builds its policy from the host policy and its own settings', function (): void {
    config()->set('webhooks.ssrf.allowed_hosts', ['hooks.example.com']);
    config()->set('webhooks.outbound.follow_redirects', true);
    config()->set('webhooks.outbound.max_redirects', 2);

    $policy = app(DestinationPolicyFactory::class)->make();

    expect($policy->allowedHosts)->toBe(['hooks.example.com'])
        ->and($policy->schemes)->toBe(['https'])
        ->and($policy->maxResponseBytes)->toBe(4096)
        ->and($policy->redirects->max)->toBe(2)
        ->and($policy->redirects->forwardHeaders)->toBe(['webhook-id', 'webhook-timestamp', 'webhook-signature']);
});

it('stops_sending_a_stored_header_whose_override_was_revoked', function (): void {
    Http::fake(['*' => Http::response('ok')]);
    config()->set('webhooks.outbound.headers.allowed_overrides', ['authorization']);
    $endpoint = Webhooks::outbound()->create('https://hooks.example.com/in', headers: ['Authorization' => 'Bearer t', 'X-Tenant' => 'acme'])->endpoint;
    // A row written around the actions is checked at send time too.
    $raw = outboundEndpoint(attributes: ['headers' => ['content-length' => '1', 'x-other' => 'ok']]);

    config()->set('webhooks.outbound.headers.allowed_overrides', []);
    attempt(Webhooks::send($endpoint, 'invoice.paid', []));
    attempt(Webhooks::send($raw, 'invoice.paid', []));

    Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Tenant') && ! $request->hasHeader('Authorization'));
    Http::assertSent(fn (ClientRequest $request): bool => $request->hasHeader('X-Other') && $request->header('Content-Length') !== ['1']);
    Http::assertNotSent(fn (ClientRequest $request): bool => $request->hasHeader('Authorization'));
});
