<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RoundlyConsulting\Sentinel\Accessors\MacsAccessor;
use RoundlyConsulting\Sentinel\Enums\Algorithm;
use RoundlyConsulting\Sentinel\SentinelManager;
use RoundlyConsulting\Webhooks\Enums\SecretEncoding;
use SensitiveParameter;

/**
 * The one seam every webhook MAC goes through: sentinel's `Sentinel::macs()` (constant time over
 * every secret, no early exit). This class only turns stored secret strings into key bytes; it
 * never computes or compares a MAC itself. Resolved per use, so `Sentinel::fake()` sees every call.
 *
 * @internal
 */
final readonly class MacEngine
{
    public function __construct(private SentinelManager $sentinel) {}

    /**
     * The index (into `$secrets`) of the secret the raw MAC matches, or null. A secret that does
     * not decode under the encoding never matches.
     *
     * @param  list<string>  $secrets
     */
    public function matches(string $message, #[SensitiveParameter] string $mac, #[SensitiveParameter] array $secrets, SecretEncoding $encoding, Algorithm $algorithm = Algorithm::HmacSha256): ?int
    {
        $keys = [];
        $positions = [];

        foreach (array_slice($secrets, 0, MacsAccessor::MAX_SECRETS) as $index => $secret) {
            $bytes = Secrets::keyBytes($secret, $encoding);

            if ($bytes !== null) {
                $keys[] = $bytes;
                $positions[] = $index;
            }
        }

        if ($keys === []) {
            return null;
        }

        $matched = $this->sentinel->macs()->verify($message, $mac, $keys, $algorithm);

        return $matched === null ? null : $positions[$matched] ?? null;
    }

    /**
     * The raw MAC of the message under each secret that decodes, in order.
     *
     * @param  list<string>  $secrets
     * @return list<string>
     */
    public function sign(string $message, #[SensitiveParameter] array $secrets, SecretEncoding $encoding, Algorithm $algorithm = Algorithm::HmacSha256): array
    {
        $macs = [];

        foreach ($secrets as $secret) {
            $bytes = Secrets::keyBytes($secret, $encoding);

            if ($bytes !== null) {
                $macs[] = $this->sentinel->macs()->sign($message, $bytes, $algorithm);
            }
        }

        return $macs;
    }
}
