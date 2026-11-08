<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use RoundlyConsulting\Webhooks\Contracts\SignatureScheme;
use RoundlyConsulting\Webhooks\Contracts\SignsOutboundWebhooks;
use RoundlyConsulting\Webhooks\Facades\Webhooks;
use RoundlyConsulting\Webhooks\Models\InboundDelivery;
use RoundlyConsulting\Webhooks\Testing\SignedWebhookRequest;

it('make_scheme_writes_into_the_sandbox', function (): void {
    $this->artisan('webhooks:make-scheme', ['recipe' => 'github', 'name' => 'AcmeGitHubScheme'])->expectsOutputToContain('AcmeGitHubScheme')->assertSuccessful();

    $path = $this->sandbox.'/app/Webhooks/Schemes/AcmeGitHubScheme.php';

    // Into the host's app/ — here the per-test sandbox, never the shared skeleton.
    expect(app_path('Webhooks/Schemes/AcmeGitHubScheme.php'))->toBe($path)
        ->and(file_exists($path))->toBeTrue()
        ->and(file_get_contents($path))->toContain('final readonly class AcmeGitHubScheme implements SignatureScheme, SignsOutboundWebhooks')
        ->not->toContain('{{');
});

it('generated recipes verify what they sign', function (string $recipe, string $class, string $secret, array $payload): void {
    Queue::fake();
    $this->artisan('webhooks:make-scheme', ['recipe' => $recipe, 'name' => $class])->assertSuccessful();

    $source = (string) file_get_contents($this->sandbox.'/app/Webhooks/Schemes/'.$class.'.php');
    $namespace = preg_match('/^namespace (.+);$/m', $source, $match) === 1 ? $match[1] : '';
    require $this->sandbox.'/app/Webhooks/Schemes/'.$class.'.php';
    $fqcn = $namespace.'\\'.$class;

    expect(is_subclass_of($fqcn, SignatureScheme::class))->toBeTrue()
        ->and(is_subclass_of($fqcn, SignsOutboundWebhooks::class))->toBeTrue();

    Webhooks::schemes()->extend($recipe, $fqcn);
    $endpoint = Webhooks::inbound()->create(scheme: $recipe, secret: $secret)->endpoint;

    SignedWebhookRequest::for($endpoint)->payload($payload)->send()->assertStatus(202);
    SignedWebhookRequest::for($endpoint)->payload($payload)->tamper()->send()->assertStatus(401);
    expect(InboundDelivery::query()->count())->toBe(1);
})->with([
    'github' => ['github', 'GeneratedGitHubScheme', 'gh-secret', ['action' => 'opened']],
    'stripe' => ['stripe', 'GeneratedStripeScheme', 'whsec_StripeStyle', ['id' => 'evt_9', 'type' => 'invoice.paid']],
]);

it('the blank recipe compiles and refuses until filled in', function (): void {
    Queue::fake();
    $this->artisan('webhooks:make-scheme')->assertSuccessful();
    require $this->sandbox.'/app/Webhooks/Schemes/WebhookScheme.php';

    $source = (string) file_get_contents($this->sandbox.'/app/Webhooks/Schemes/WebhookScheme.php');
    $fqcn = (preg_match('/^namespace (.+);$/m', $source, $match) === 1 ? $match[1] : '').'\\WebhookScheme';
    Webhooks::schemes()->extend('blank', $fqcn);
    $endpoint = Webhooks::inbound()->create(scheme: 'blank', secret: 'plain')->endpoint;
    $body = '{"a":1}';

    $this->call('POST', $endpoint->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => hash_hmac('sha256', $body, 'plain'), 'HTTP_X_DELIVERY_ID' => 'd-1'], $body)->assertStatus(202);
    $this->call('POST', $endpoint->url(), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'zz', 'HTTP_X_DELIVERY_ID' => 'd-2'], $body)->assertStatus(401);
});

it('refuses an unknown recipe, a bad name and an existing class', function (): void {
    $this->artisan('webhooks:make-scheme', ['recipe' => 'paypal'])->expectsOutputToContain('github, stripe or blank')->assertExitCode(2);
    $this->artisan('webhooks:make-scheme', ['recipe' => 'github', 'name' => '1bad'])->assertExitCode(2);

    $this->artisan('webhooks:make-scheme', ['recipe' => 'stripe'])->assertSuccessful();
    $this->artisan('webhooks:make-scheme', ['recipe' => 'stripe'])->expectsOutputToContain('already exists')->assertFailed();
    $this->artisan('webhooks:make-scheme', ['recipe' => 'stripe', '--force' => true])->assertSuccessful();
});
