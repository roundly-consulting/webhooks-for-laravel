<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\DataTransferObjects;

use RoundlyConsulting\Webhooks\Enums\FindingLevel;

/**
 * One `webhooks:doctor` finding: its level, the check's stable name (`sweep_stalled`, …) and an
 * optional detail — an endpoint id, a count or a config error. Never a secret.
 */
final readonly class DoctorFinding
{
    public function __construct(
        public FindingLevel $level,
        public string $check,
        public ?string $detail = null,
    ) {}

    public static function error(string $check, ?string $detail = null): self
    {
        return new self(FindingLevel::Error, $check, $detail);
    }

    public static function warning(string $check, ?string $detail = null): self
    {
        return new self(FindingLevel::Warning, $check, $detail);
    }

    public function message(): string
    {
        return (string) trans('webhooks::commands.doctor.'.$this->check);
    }
}
