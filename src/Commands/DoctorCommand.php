<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use RoundlyConsulting\Webhooks\Support\Doctor;

/**
 * Check the settings that silently weaken delivery or safety. Exits 1 on an error.
 */
final class DoctorCommand extends Command
{
    protected $signature = 'webhooks:doctor';

    protected $description = 'Check the webhooks setup';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->findings();

        if ($findings === []) {
            $this->components->info((string) trans('webhooks::commands.doctor_ok'));

            return self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $line = (string) trans('webhooks::commands.doctor.'.$finding['check']).(isset($finding['detail']) ? ' ['.$finding['detail'].']' : '');
            $finding['level'] === 'error' ? $this->components->error($line) : $this->components->warn($line);
        }

        return in_array('error', array_column($findings, 'level'), true) ? self::FAILURE : self::SUCCESS;
    }
}
