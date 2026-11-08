<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Enums\AttemptError;
use RoundlyConsulting\Webhooks\Enums\OutboundStatus;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Tests\Support\LocalReceiver;

/**
 * Real requests: Laravel's client, Guzzle's curl handler, real curl, a real receiver on
 * 127.0.0.1 — under a name (`*.invalid`) no DNS knows, so a request lands only because of the
 * pin. These are the only tests where the pinning options pass Guzzle's CurlFactory; on CI a skip
 * is a failure (WEBHOOKS_REQUIRE_NETWORK_TESTS=1).
 */
beforeEach(function (): void {
    if (! LocalReceiver::available()) {
        if (getenv('WEBHOOKS_REQUIRE_NETWORK_TESTS') === '1') {
            $this->fail('The real-network tests must run on CI (proc_open + ext-curl).');
        }

        $this->markTestSkipped('needs proc_open and ext-curl');
    }

    $this->receiver = LocalReceiver::start();
    Queue::fake();
    config()->set('webhooks.outbound.allow_http', true);
    config()->set('webhooks.ssrf.allowed_ranges', ['127.0.0.0/8']);
});

afterEach(function (): void {
    if (isset($this->receiver)) {
        $this->receiver->stop();
    }
});

it('the_pinning_options_pass_guzzles_curl_factory', function (): void {
    publicDns(['hooks.invalid' => '127.0.0.1']);
    $endpoint = Webhooks::outbound()->create("http://hooks.invalid:{$this->receiver->port}/in")->endpoint;

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]));

    expect($delivery->status)->toBe(OutboundStatus::Succeeded)
        ->and($delivery->last_response_status)->toBe(200)
        ->and($this->receiver->requests())->toHaveCount(1);
});

it('pinning_reaches_the_vetted_address_for_a_name_dns_does_not_know', function (): void {
    publicDns(['hooks.invalid' => '127.0.0.1']);
    $created = Webhooks::outbound()->create("http://hooks.invalid:{$this->receiver->port}/in");

    attempt(Webhooks::send($created->endpoint, 'invoice.paid', ['invoice' => 42]));

    [$request] = $this->receiver->requests();
    $headers = $request['headers'];

    expect($request['method'])->toBe('POST')
        ->and($request['path'])->toBe('/in')
        ->and($headers['host'])->toBe("hooks.invalid:{$this->receiver->port}")
        ->and($headers['webhook-signature'])->toBe(documentedSignature($created->secret, $headers['webhook-id'], $headers['webhook-timestamp'], $request['body']))
        ->and(OutboundAttempt::query()->sole()->remote_address)->toBe('127.0.0.1');
});

it('attempts_always_use_the_curl_handler', function (): void {
    publicDns(['hooks.invalid' => '127.0.0.1']);
    $endpoint = Webhooks::outbound()->create("http://hooks.invalid:{$this->receiver->port}/status/500")->endpoint;

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]));

    // Real curl transfer stats (primary_ip) — only the forced curl handler produces them.
    expect($delivery->status)->toBe(OutboundStatus::Retrying)
        ->and($delivery->last_error_code)->toBe(AttemptError::HttpStatus)
        ->and(OutboundAttempt::query()->sole()->remote_address)->toBe('127.0.0.1')
        ->and(OutboundAttempt::query()->sole()->response_body)->toBe('status 500');
});

it('a_connection_to_an_unvetted_address_is_refused', function (): void {
    // The name is vetted to 127.0.0.2 — nothing listens there. The request must go to the vetted
    // address (and fail), never to the receiver on 127.0.0.1 that system DNS could have named.
    publicDns(['hooks.invalid' => '127.0.0.2']);
    config()->set('webhooks.outbound.timeouts.connect', 1);
    $endpoint = Webhooks::outbound()->create("http://hooks.invalid:{$this->receiver->port}/in")->endpoint;

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]));

    expect($delivery->status)->toBe(OutboundStatus::Retrying)
        // Refused on Linux (127.0.0.2 is loopback), a connect timeout on macOS (it is not).
        ->and($delivery->last_error_code)->toBeIn([AttemptError::Connection, AttemptError::Timeout])
        ->and($this->receiver->requests())->toBe([]);
});

it('a_redirect_to_a_private_ip_is_blocked_on_the_wire', function (): void {
    config()->set('webhooks.outbound.follow_redirects', true);
    config()->set('webhooks.ssrf.allowed_ranges', ['127.0.0.1/32']);
    publicDns(['hooks.invalid' => '127.0.0.1']);
    $endpoint = Webhooks::outbound()->create("http://hooks.invalid:{$this->receiver->port}/status/307")->endpoint;

    $delivery = attempt(Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]));

    // 307 without a Location is returned unfollowed — the hop never leaves the vetted address.
    expect($this->receiver->requests())->toHaveCount(1)
        ->and($delivery->status)->not->toBe(OutboundStatus::Succeeded);
});

it('guard_off_reads_at_most_max_response_bytes', function (): void {
    config()->set('webhooks.ssrf.enabled', false);
    $endpoint = Webhooks::outbound()->create("http://127.0.0.1:{$this->receiver->port}/big/5000000")->endpoint;
    $delivery = Webhooks::send($endpoint, 'invoice.paid', ['invoice' => 42]);

    gc_collect_cycles();
    $baseline = memory_get_usage();
    memory_reset_peak_usage();

    $delivery = attempt($delivery);

    $peak = memory_get_peak_usage() - $baseline;
    $attempt = OutboundAttempt::query()->sole();

    expect($delivery->status)->toBe(OutboundStatus::Succeeded)
        ->and($attempt->response_truncated)->toBeTrue()
        ->and(strlen((string) $attempt->response_body))->toBe(4096)
        ->and($attempt->remote_address)->toBe('127.0.0.1')
        // A 5 MB answer never lands in the worker's memory.
        ->and($peak)->toBeLessThan(2 * 1024 * 1024);
});
