<?php

declare(strict_types=1);

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Http\Resources\OutboundAttemptResource;
use RoundlyConsulting\Webhooks\Models\OutboundAttempt;
use RoundlyConsulting\Webhooks\Support\BodyReader;

/**
 * A receiver's answer is read up to `outbound.max_response_bytes` in every mode, and the attempt
 * row says when it was cut.
 */
beforeEach(function (): void {
    Queue::fake();
    publicDns();
});

it('guard_on_records_the_truncation_flag', function (): void {
    Http::fake(['*' => Http::response(str_repeat('x', 5000))]);

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42]));

    $attempt = OutboundAttempt::query()->sole();
    expect($attempt->response_truncated)->toBeTrue()
        ->and($attempt->response_body)->toBe(str_repeat('x', 4096));
});

it('a_short_answer_is_not_flagged_truncated', function (bool $guard): void {
    config()->set('webhooks.ssrf.enabled', $guard);
    Http::fake(['*' => Http::response(str_repeat('x', 4096))]);

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42]));

    $attempt = OutboundAttempt::query()->sole();
    expect($attempt->response_truncated)->toBeFalse()
        ->and($attempt->response_body)->toBe(str_repeat('x', 4096));
})->with(['guard on' => true, 'guard off' => false]);

it('guard_off_cuts_a_faked_long_answer_too', function (): void {
    config()->set('webhooks.ssrf.enabled', false);
    Http::fake(['*' => Http::response(str_repeat('y', 9000), 500)]);

    attempt(Webhooks::send(outboundEndpoint(), 'invoice.paid', ['invoice' => 42]));

    $attempt = OutboundAttempt::query()->sole();
    expect($attempt->response_truncated)->toBeTrue()
        ->and($attempt->response_status)->toBe(500)
        ->and($attempt->response_body)->toBe(str_repeat('y', 4096));
});

it('the_attempt_resource_renders_response_truncated', function (): void {
    $attempt = OutboundAttempt::factory()->create(['response_truncated' => true]);

    expect((new OutboundAttemptResource($attempt))->resolve(Request::create('/')))->toHaveKey('response_truncated', true)
        ->and(OutboundAttempt::factory()->create()->response_truncated)->toBeFalse();
});

it('body_reader_keeps_utf8_safe_when_cut_mid_sequence', function (): void {
    $excerpt = BodyReader::read(Utils::streamFor(str_repeat('a', 9).'€uro'), 10);

    // "€" is three bytes: cut after the first, it is dropped — never half a character.
    expect($excerpt->text)->toBe(str_repeat('a', 9))
        ->and($excerpt->truncated)->toBeTrue()
        ->and(mb_check_encoding((string) $excerpt->text, 'UTF-8'))->toBeTrue();
});

it('body_reader_scrubs_invalid_bytes_and_nul', function (): void {
    $excerpt = BodyReader::read(Utils::streamFor("ok\0\xff!"), 100);

    expect($excerpt->truncated)->toBeFalse()
        ->and(mb_check_encoding((string) $excerpt->text, 'UTF-8'))->toBeTrue()
        ->and($excerpt->text)->not->toContain("\0")
        ->and(BodyReader::read(Utils::streamFor(''), 100)->text)->toBeNull()
        ->and(BodyReader::read(Utils::streamFor(''), 100)->truncated)->toBeFalse();
});
