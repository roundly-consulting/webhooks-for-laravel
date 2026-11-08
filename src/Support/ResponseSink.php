<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Support;

use RuntimeException;

/**
 * The response sink of an unguarded attempt (`ssrf.enabled` off): a PHP stream that keeps at
 * most `$limit` bytes and discards the rest. Every write reports all its bytes as written, so
 * curl finishes the transfer normally (status, headers and transfer stats intact) within the
 * attempt's total timeout — but the worker never holds more than the limit in memory. The
 * guarded path gets the same cap from safe-fetch.
 *
 * Opened as `fopen()`-able resource rather than a PSR-7 stream because Laravel's `Http::fake()`
 * writes a stub's body into the sink with `fwrite()`.
 *
 * @internal
 */
final class ResponseSink
{
    private const string PROTOCOL = 'webhooks-response-sink';

    /** @var resource|null set by PHP on every stream wrapper */
    public $context;

    private string $buffer = '';

    private int $limit = 0;

    private int $position = 0;

    /**
     * @return resource
     */
    public static function open(int $limit): mixed
    {
        if (! in_array(self::PROTOCOL, stream_get_wrappers(), true)) {
            stream_wrapper_register(self::PROTOCOL, self::class);
        }

        return fopen(self::PROTOCOL.'://'.max(0, $limit), 'w+b') ?: throw new RuntimeException('The response sink could not be opened.');
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $this->limit = (int) substr($path, strlen(self::PROTOCOL) + 3);

        return true;
    }

    public function stream_write(string $data): int
    {
        $room = $this->limit - strlen($this->buffer);

        if ($room > 0) {
            $this->buffer .= substr($data, 0, $room);
        }

        $this->position = strlen($this->buffer);

        return strlen($data);
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->buffer, $this->position, $count);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= strlen($this->buffer);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($this->buffer) + $offset,
            default => $offset,
        };

        if ($target < 0 || $target > strlen($this->buffer)) {
            return false;
        }

        $this->position = $target;

        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => strlen($this->buffer)];
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        $this->buffer = '';
        $this->position = 0;
    }
}
