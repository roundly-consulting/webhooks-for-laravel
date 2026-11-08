<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

/**
 * A signed inbound request built for a test: where to post it, its headers and its exact body.
 */
final readonly class SignedRequestData
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $uri,
        public array $headers,
        public string $body,
    ) {}

    /**
     * The headers as `$_SERVER` keys, for a test case's own call:
     * `$this->call('POST', $data->uri, [], [], [], $data->server(), $data->body)`.
     *
     * @return array<string, string>
     */
    public function server(): array
    {
        $server = [];

        foreach ($this->headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        return $server;
    }
}
