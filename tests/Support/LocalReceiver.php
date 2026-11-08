<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Support;

use RuntimeException;

/**
 * A real HTTP receiver on 127.0.0.1 (PHP's built-in server), so outbound attempts run through
 * Laravel's real client, Guzzle's real curl handler and real curl — the only place the pinning
 * options meet `CurlFactory`. Every request it receives is logged.
 */
final class LocalReceiver
{
    /**
     * @param  resource  $process
     */
    private function __construct(
        private $process,
        public readonly int $port,
        private readonly string $log,
    ) {}

    public static function available(): bool
    {
        return function_exists('proc_open') && extension_loaded('curl');
    }

    public static function start(): self
    {
        $log = (string) tempnam(sys_get_temp_dir(), 'webhooks-receiver-');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $port = random_int(20000, 60000);
            $process = proc_open(
                [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__.'/files/router.php'],
                [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
                null,
                [...getenv(), 'WEBHOOKS_SERVER_LOG' => $log],
            );

            if (! is_resource($process)) {
                continue;
            }

            for ($wait = 0; $wait < 50; $wait++) {
                $socket = @fsockopen('127.0.0.1', $port, $errorCode, $errorMessage, 0.1);

                if (is_resource($socket)) {
                    fclose($socket);

                    return new self($process, $port, $log);
                }

                usleep(50_000);
            }

            proc_terminate($process);
        }

        throw new RuntimeException('The local receiver did not start.');
    }

    /**
     * @return list<array{method: string, path: string, headers: array<string, string>, body: string}>
     */
    public function requests(): array
    {
        $lines = file($this->log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_values(array_filter(array_map(static fn (string $line): mixed => json_decode($line, true), $lines), static fn (mixed $entry): bool => is_array($entry) && ($entry['method'] ?? '') !== ''));
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->log);
    }
}
