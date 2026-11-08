<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\Webhooks\Actions\ExampleWebhooksAction;
use RoundlyConsulting\Webhooks\DataTransferObjects\ExampleWebhooksData;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Testing\WebhooksFake;
use RoundlyConsulting\Webhooks\WebhooksManager;

/**
 * The public API contract — Actions → Manager → Facade (+ fake) — pinned from day one.
 * Grow these tests with the API: every facade and sub-accessor method through the facade,
 * one DI resolution of the manager, cross-scope refusals, and a passing plus a failing case
 * for every fake assert.
 */
it('pins the facade contract', function (): void {
    expect(Webhooks::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});

it('runs the example through the facade', function (): void {
    expect(Webhooks::example(new ExampleWebhooksData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example through an injected manager', function (): void {
    $manager = app(WebhooksManager::class);

    expect($manager)->toBe(app(WebhooksManager::class))
        ->and($manager->example(new ExampleWebhooksData('Ada')))->toBe('Hello, Ada!');
});

it('runs the example action directly', function (): void {
    expect(app(ExampleWebhooksAction::class)->execute(new ExampleWebhooksData('Ada')))
        ->toBe('Hello, Ada!');
});

it('resolves the action through the container so a host override applies', function (): void {
    app()->bind(ExampleWebhooksAction::class, static fn (): never => throw new RuntimeException('overridden'));

    Webhooks::example(new ExampleWebhooksData('Ada'));
})->throws(RuntimeException::class, 'overridden');

it('records facade and injected calls under the fake without running the action', function (): void {
    app()->bind(ExampleWebhooksAction::class, static fn (): never => throw new RuntimeException('ran'));

    $fake = Webhooks::fake();

    Webhooks::example(new ExampleWebhooksData('Ada'));
    app(WebhooksManager::class)->example(new ExampleWebhooksData('Grace'));

    expect(app(WebhooksManager::class))->toBeInstanceOf(WebhooksFake::class);

    $fake->assertExampleCalled();
    $fake->assertExampleCalled(static fn (ExampleWebhooksData $data): bool => $data->name === 'Ada');
    Webhooks::assertExampleCalled(static fn (ExampleWebhooksData $data): bool => $data->name === 'Grace');
});

it('fails assertExampleCalled when nothing was called', function (): void {
    Webhooks::fake()->assertExampleCalled();
})->throws(ExpectationFailedException::class, 'Expected example() to be called, but it was not.');

it('fails assertExampleCalled when no call matches', function (): void {
    $fake = Webhooks::fake();

    Webhooks::example(new ExampleWebhooksData('Ada'));

    $fake->assertExampleCalled(static fn (ExampleWebhooksData $data): bool => $data->name === 'Grace');
})->throws(ExpectationFailedException::class, 'no call matched');

it('passes assertNothingCalled on an untouched fake', function (): void {
    Webhooks::fake()->assertNothingCalled();
});

it('fails assertNothingCalled after a call', function (): void {
    $fake = Webhooks::fake();

    Webhooks::example(new ExampleWebhooksData('Ada'));

    $fake->assertNothingCalled();
})->throws(ExpectationFailedException::class, 'example() was called 1 time(s)');

it('declares the global alias for the facade and never a core facade name', function (): void {
    /** @var array{extra: array{laravel: array{aliases: array<string, string>}}} $composer */
    $composer = json_decode((string) file_get_contents(__DIR__.'/../../composer.json'), true, flags: JSON_THROW_ON_ERROR);

    $aliases = $composer['extra']['laravel']['aliases'];
    $alias = (string) array_key_first($aliases);

    // Both halves: a facade class Laravel ships, and a global alias it registers without one
    // there (`Str`, `Number`, `Js`, …). Compared case-insensitively, as PHP resolves class names.
    $coreAliases = array_map(strtolower(...), array_keys(Facade::defaultAliases()->all()));

    expect($aliases)->toBe([class_basename(Webhooks::class) => Webhooks::class])
        ->and(class_exists('Illuminate\\Support\\Facades\\'.$alias))->toBeFalse()
        ->and($coreAliases)->not->toContain(strtolower($alias));
});
