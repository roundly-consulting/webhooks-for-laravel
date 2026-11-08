# Changelog

All notable changes to `webhooks-for-laravel` are documented in this file. The format
follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## Unreleased

Initial public release.

### Added

- **Inbound endpoints** — one per sender, owned by any model (or none): an unguessable URL key, a
  secret shown once (generated `whsec_…`, or the sender's imported verbatim), rotation with a grace
  period in which both secrets verify, a handler alias, per-endpoint overrides.
- **The inbound pipeline** behind `POST {prefix}/{key}` (or your own with `Route::webhooks('hooks')`,
  which also keeps Laravel's body-decoding middleware off it, or `Webhooks::inbound()->receive()`): unknown and disabled keys answer the same 404, a rejection
  guard per endpoint and IP, size (413) and content-type (415) checks before any signature work,
  signature and timestamp tolerance (401), a per-endpoint limit on verified deliveries (429),
  JSON decoded to arrays only (400), replay and idempotency through sentinel (200 duplicate or
  409), then the sanitized payload is stored and queued — 202. Problem responses are RFC 9457.
- **Signature schemes as drivers** — `default` (Standard Webhooks v1: `webhook-id`,
  `webhook-timestamp`, `webhook-signature`), `rfc9421` (HTTP message signatures through sentinel,
  bound to the endpoint) and your own via `Contracts\SignatureScheme` (GitHub- and Stripe-style
  recipes). Every MAC runs through `Sentinel::macs()`; a scheme never sees a secret.
- **Queued processing** — `WebhookReceived`, then the endpoint's handler, exactly once per
  delivery (sentinel's idempotent job middleware); retries, `WebhookProcessingFailed`, and replay
  of a stored delivery flagged as a replay.
- **Outbound endpoints and sending** — `Webhooks::send()`, `Webhooks::broadcast()` and
  `Webhooks::for($owner)->broadcast()`: an envelope signed with the default scheme (both secrets
  during a rotation), retries with exponential backoff and jitter honouring `Retry-After` (8
  attempts over about 24 hours by default), per-endpoint throttling through
  http-client-rate-limits, connect / total timeouts, an answer read only up to `outbound.max_response_bytes` with the SSRF
  guard on or off (`response_truncated` on the attempt when cut), auto-disable after
  consecutive failed deliveries or a 410 (`EndpointDisabled`), manual redelivery — one delivery,
  or after an outage every failed (and optionally cancelled) delivery of an endpoint with
  `outbound()->redeliverFailed()` / `webhooks:redeliver --endpoint=`.
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
  `webhooks:rotate-secret`, `webhooks:redeliver`, `webhooks:replay`. The doctor (`--json` for
  monitoring) also flags a stalled sweep, the schedule switched off, volatile (array) limiter and
  idempotency stores, a queue `retry_after` shorter than the handler timeout, and an SQS
  `max_delay` over 900 seconds.
- **Testing** — `Webhooks::fake()` recording sends, broadcasts, redeliveries, replays, rotations and
  disables, each with an assertion and a negative (`assertNothingBroadcast()`,
  `assertSecretNotRotated()`, …); `assertSent('invoice.paid', payload: …)` without the endpoint;
  `SignedWebhookRequest` for correctly (or deliberately badly — tampered, stale, unsigned, a
  header dropped) signed inbound requests under any scheme (its own `sign()`, or `signUsing()`),
  `FakeHostResolver` for DNS.
- Facade, injectable `WebhooksManager` and single-purpose actions; swappable models; bigint, uuid
  or ulid keys; English and Slovak translations.
