<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;
use RoundlyConsulting\Webhooks\Exceptions\SecretRotationException;
use RoundlyConsulting\Webhooks\Support\Clock;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * Rotate an endpoint's secret and print the new one — once.
 */
final class RotateSecretCommand extends Command
{
    protected $signature = 'webhooks:rotate-secret {direction : inbound or outbound} {endpoint : The endpoint id} {--grace= : Seconds the old secret keeps working}';

    protected $description = 'Rotate a webhook endpoint secret';

    public function handle(WebhooksManager $webhooks): int
    {
        $direction = $this->argument('direction');
        $id = $this->argument('endpoint');
        $grace = $this->option('grace');

        if (! in_array($direction, ['inbound', 'outbound'], true) || ($grace !== null && ! ctype_digit($grace))) {
            $this->components->error((string) trans('webhooks::commands.invalid_arguments'));

            return self::INVALID;
        }

        $until = $grace === null ? null : Clock::now()->addSeconds((int) $grace);

        try {
            $rotated = $direction === 'inbound'
                ? $webhooks->inbound()->rotateSecret($webhooks->inbound()->find($id), $until)
                : $webhooks->outbound()->rotateSecret($webhooks->outbound()->find($id), $until);
        } catch (EndpointNotFoundException) {
            $this->components->error((string) trans('webhooks::commands.endpoint_not_found'));

            return self::FAILURE;
        } catch (SecretRotationException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info((string) trans('webhooks::commands.rotated', ['until' => $rotated->graceEndsAt->toIso8601ZuluString()]));
        $this->line($rotated->secret);

        return self::SUCCESS;
    }
}
