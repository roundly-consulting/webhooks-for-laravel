<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * What a scheme verifies: lower-cased headers, the exact raw body, when it arrived, and the
 * endpoint's public reference (its URL key), its scheme options, and the original request (for
 * schemes that sign request components, such as RFC 9421).
 */
final readonly class IncomingWebhook
{
    /**
     * @param  array<string, string>  $headers  lower-cased names, the first value of each
     * @param  array<string, string>  $schemeOptions  the endpoint's scheme options
     */
    public function __construct(
        public array $headers,
        public string $body,
        public CarbonImmutable $receivedAt,
        public string $endpointKey,
        public int $toleranceSeconds,
        public array $schemeOptions = [],
        public ?Request $request = null,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
