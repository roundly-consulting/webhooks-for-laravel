# Changelog

All notable changes to `webhooks-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

### Fixed

- An inbound body guard on a scheme with a signed timestamp (a Stripe-style recipe whose body has no
  id, or any scheme whose id is unsigned) now lasts twice the endpoint's tolerance (at least 60
  seconds) instead of `inbound.dedupe_ttl`, so two genuine deliveries with the same body minutes
  apart are no longer merged into one; past the tolerance the timestamp itself refuses the replay.
  RFC 9421 and timestamp-less schemes keep the full guard.
- An outbound delivery this side cannot sign (an RFC 9421 endpoint with no key id, a scheme gone
  from the registry) no longer counts towards `outbound.disable_after`: the receiver's endpoint is
  not auto-disabled and its queued deliveries are not cancelled for a fault on the sender's side.
- `Webhooks::outbound()->create()` and `update()` refuse a verify-only scheme (no `sign()`, not RFC
  9421) with an `InvalidEndpointException` instead of storing an endpoint whose every delivery
  fails. An RFC 9421 endpoint without a key id is still accepted; `webhooks:doctor` now warns about
  it (`rfc9421_outbound_no_key`).
- `Webhooks::send(..., id:)` repeated after the endpoint was disabled, deleted or unsubscribed from
  the type now returns the delivery queued the first time, as documented, instead of throwing
  `EndpointNotAcceptingException`; `Webhooks::fake()` behaves the same.

## 1.0.0 - 2026-11-08

Initial public release.

### Added

- **Inbound endpoints** — one per sender, owned by any model (or none): an unguessable URL key, a
  secret shown once (generated `whsec_…`, or the sender's imported verbatim), rotation with a
  grace period in which both secrets verify, a handler alias, per-endpoint overrides.
- **The inbound pipeline** behind `POST {prefix}/{key}` (or your own with
  `Route::webhooks('hooks')`, which also keeps Laravel's input-rewriting middleware off it, or
  `Webhooks::inbound()->receive()`): unknown and disabled keys answer the same 404, a rejection
  guard per endpoint and IP, size (413) and content-type (415) checks before any signature work,
  a per-endpoint limit on verified deliveries (429, checked before the signature), signature and
  timestamp tolerance (401; an RFC 9421 nonce is checked unused there but recorded only together
  with the stored delivery, through sentinel's deferred-nonce API — a request refused after its
  signature verified, with 400, 500 or a database error, is accepted when resent unchanged, and of
  two concurrent copies one is stored), JSON decoded to arrays only (400), replay and idempotency through sentinel (200
  duplicate or 409; a body guard for unsigned ids, kept against the stored deliveries when the
  format has no signed timestamp, skipped where a verified RFC 9421 nonce already rules out a
  replay), then the sanitized payload is stored and queued — 202 (also when the idempotency store
  then fails to keep the result). Problem responses are RFC 9457.
- **Signature schemes as drivers** — `default` (Standard Webhooks v1: `webhook-id`,
  `webhook-timestamp`, `webhook-signature`), `rfc9421` (HTTP message signatures through sentinel,
  bound to the endpoint; outbound signatures cover `@query` when the URL has one) and your own via
  `Contracts\SignatureScheme` (GitHub- and Stripe-style recipes; `VerifiedWebhook::$replayProtected`
  for a scheme that refuses a second use of a request itself). Every MAC runs through `Sentinel::macs()`; a scheme never sees a secret. Schemes and
  handlers registered in a service provider's `boot()` hold for every request, queue job and
  Octane request.
- **Queued processing** — `WebhookReceived`, then the endpoint's handler (a class or a closure;
  `InboundWebhook::get('data.id')`, `header()`, `owner()`), once per delivery (sentinel's
  idempotent job middleware); retries, `WebhookProcessingFailed` (the failure stored as the
  exception's short name and code — never its message, which may quote the payload), and replay of
  a stored delivery flagged as a replay.
- **Outbound endpoints and sending** — `Webhooks::send()` (idempotent with `id:` — a repeat
  returns the first delivery, for listeners that may run twice), `Webhooks::broadcast()` and
  `Webhooks::for($owner)->broadcast()` (or put `Contracts\BroadcastsAsWebhook` on your own event
  and dispatch it): an envelope signed with the default scheme (both secrets during a rotation),
  retries with exponential backoff and jitter honouring `Retry-After` (8 attempts over about 24
  hours by default), per-endpoint throttling through http-client-rate-limits, connect / total
  timeouts, a `User-Agent` of `"{app.name} Webhooks"` (configurable, or the endpoint's own), custom
  headers re-checked against the header rules on every attempt, an answer read only up to
  `outbound.max_response_bytes` with the SSRF guard on or off (`response_truncated` on the attempt
  when cut), auto-disable after consecutive failed deliveries or a 410 (`EndpointDisabled`),
  manual redelivery — one delivery, or after an outage every failed (and optionally cancelled)
  delivery of an endpoint with `outbound()->redeliverFailed()` / `webhooks:redeliver --endpoint=`.
- **SSRF protection** through safe-fetch-for-laravel — https only by default, every resolved
  address vetted against the private, loopback, link-local, metadata and reserved ranges, the
  connection pinned to the vetted addresses, redirects off (307/308 with per-hop vetting when on),
  allow and block lists including the app's own hosts.
- **Delivery log** — inbound deliveries, outbound deliveries and their attempts (status, attempts,
  response code, duration, error) without secrets; payloads encrypted at rest, a sanitizer hook,
  retention via `webhooks:prune`, JSON resources with a swappable owner reference. Deleting an
  endpoint wipes its secrets (and an outbound URL and headers) at once; the prune removes the row
  once no delivery points at it, and a delivery still waiting for it fails (`endpoint_deleted`).
- **Events** — `WebhookReceived`, `WebhookRejected`, `WebhookProcessingFailed`,
  `DeliverySucceeded`, `DeliveryFailed`, `EndpointDisabled`, `EndpointSecretRotated` — the
  model-carrying ones re-fetch their models in a queued listener. `WebhookReceived` fires on every
  attempt of a run; `InboundWebhook::isFirstAttempt()` tells them apart.
- **Commands** — `webhooks:install`, `webhooks:doctor`, `webhooks:retry`, `webhooks:prune`,
  `webhooks:rotate-secret`, `webhooks:redeliver`, `webhooks:replay`, `webhooks:endpoints` (a
  listing without secrets), `webhooks:enable` and `webhooks:ping` (a `webhook.ping` test event,
  also `outbound()->ping()`), `webhooks:make-scheme github|stripe|blank` (a signature-scheme class
  written into your app from a recipe). The doctor (`--json` for monitoring) also flags a stalled
  sweep, the schedule switched off, volatile (array) limiter and idempotency stores, a queue
  `retry_after` shorter than the handler timeout, an SQS `max_delay` over 900 seconds, and an RFC
  9421 profile that turns its nonce or content digest off, and a sentinel nonce store that cannot
  look a nonce up (`InspectsNonces`).
- **Testing** — `Webhooks::fake()` recording sends, broadcasts, redeliveries, replays, rotations
  and disables, each with an assertion and a negative (`assertNothingBroadcast()`,
  `assertSecretNotRotated()`, …); `assertSent('invoice.paid', payload: …)` without the endpoint;
  `SignedWebhookRequest` for correctly (or deliberately badly — tampered, stale, unsigned, a
  header dropped) signed inbound requests under any scheme (its own `sign()`, or `signUsing()`),
  `FakeHostResolver` for DNS.
- Queue-friendly jobs: Horizon tags (`webhooks`, `webhooks:delivery:{id}`) and display names; the
  sweep dispatches at most `outbound.sweep_batch` jobs per run, and a job a backlogged queue has not
  reached gets a fresh copy with ever longer patience instead of one more copy every run. An
  unexpected exception inside an attempt is recorded (`unexpected`) and retried like a failure,
  never left `sending`, and never counts towards disabling the receiver's endpoint; an attempt that
  outlived its claim records nothing over the sweep's verdict.
- Facade, injectable `WebhooksManager` and single-purpose actions; swappable models; bigint, uuid
  or ulid keys; English and Slovak translations.
