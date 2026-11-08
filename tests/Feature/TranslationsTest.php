<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use RoundlyConsulting\Webhooks\Enums\RejectionReason;
use RoundlyConsulting\Webhooks\Exceptions\EndpointNotFoundException;

/**
 * @return list<string>
 */
function webhooksLangFiles(): array
{
    return array_map(basename(...), glob(__DIR__.'/../../resources/lang/en/*.php') ?: []);
}

/**
 * @return array<string, mixed>
 */
function webhooksLang(string $locale, string $file): array
{
    return Arr::dot(require __DIR__."/../../resources/lang/{$locale}/{$file}");
}

it('translations_are_complete_in_both_languages', function (): void {
    expect(webhooksLangFiles())->toBe(['commands.php', 'enums.php', 'exceptions.php', 'problems.php']);

    foreach (webhooksLangFiles() as $file) {
        $en = webhooksLang('en', $file);
        $sk = webhooksLang('sk', $file);

        expect(array_keys($sk))->toEqualCanonicalizing(array_keys($en));

        foreach ($en as $key => $line) {
            preg_match_all('/:([a-z_]+)/', (string) $line, $enPlaceholders);
            preg_match_all('/:([a-z_]+)/', (string) $sk[$key], $skPlaceholders);

            expect($skPlaceholders[1])->toEqualCanonicalizing($enPlaceholders[1]);
        }
    }
});

it('has a problem title and an enum label for every rejection reason', function (): void {
    foreach (RejectionReason::cases() as $reason) {
        expect(webhooksLang('en', 'enums.php'))->toHaveKey('rejection_reason.'.$reason->value);

        if ($reason !== RejectionReason::EndpointDisabled) {
            expect(webhooksLang('en', 'problems.php'))->toHaveKey($reason->value);
        }
    }
});

it('speaks Slovak through the package namespace', function (): void {
    app()->setLocale('sk');

    expect(EndpointNotFoundException::make()->getMessage())->toBe('Webhookový endpoint sa nenašiel.')
        ->and(RejectionReason::StaleTimestamp->label())->toBe('Zastaraná časová pečiatka');

    $this->postJson('/webhooks/'.str_repeat('A', 32), [])->assertJson(['title' => 'Nenájdené.']);
});
