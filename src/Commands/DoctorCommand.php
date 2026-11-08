<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\DataTransferObjects\DoctorFinding;
use RoundlyConsulting\Webhooks\Enums\FindingLevel;
use RoundlyConsulting\Webhooks\Support\Doctor;

/**
 * Check the settings that silently weaken delivery or safety. Exits 1 on an error. `--json`
 * prints the findings as a JSON array for monitoring.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'webhooks:doctor {--json : Print the findings as JSON}';

    protected $description = 'Check the webhooks setup';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->findings();
        $failed = array_filter($findings, static fn (DoctorFinding $finding): bool => $finding->level === FindingLevel::Error) !== [];

        if ($this->option('json')) {
            $this->line((string) json_encode(array_map(static fn (DoctorFinding $finding): array => [
                'level' => $finding->level->value,
                'check' => $finding->check,
                'message' => $finding->message(),
                'detail' => $finding->detail,
            ], $findings), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        if ($findings === []) {
            $this->components->info((string) trans('webhooks::commands.doctor_ok'));

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $line = $finding->message().($finding->detail === null ? '' : ' ['.$finding->detail.']');
            $finding->level === FindingLevel::Error ? $this->components->error($line) : $this->components->warn($line);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
