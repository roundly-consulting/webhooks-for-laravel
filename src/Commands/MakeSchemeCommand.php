<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Write a signature-scheme class into the host app (`app/Webhooks/Schemes`) from a recipe:
 * `github` (`X-Hub-Signature-256`), `stripe` (`Stripe-Signature`) or `blank`. The package ships no
 * provider drivers — the generated class is the host's own code to adapt.
 */
final class MakeSchemeCommand extends Command
{
    private const array DEFAULT_NAMES = ['github' => 'GitHubScheme', 'stripe' => 'StripeScheme', 'blank' => 'WebhookScheme'];

    protected $signature = 'webhooks:make-scheme
        {recipe=blank : github, stripe or blank}
        {name? : The class name (GitHubScheme, StripeScheme, WebhookScheme by default)}
        {--force : Overwrite an existing class}';

    protected $description = 'Create a webhook signature scheme class from a recipe';

    public function handle(Filesystem $files): int
    {
        $recipe = (string) $this->argument('recipe');
        $name = $this->argument('name');
        $class = is_string($name) && $name !== '' ? Str::studly($name) : (self::DEFAULT_NAMES[$recipe] ?? '');

        if (! isset(self::DEFAULT_NAMES[$recipe]) || preg_match('/^[A-Z][A-Za-z0-9_]*$/', $class) !== 1) {
            $this->components->error((string) trans('webhooks::commands.invalid_recipe'));

            return self::INVALID;
        }

        $path = $this->laravel->path('Webhooks/Schemes/'.$class.'.php');

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error((string) trans('webhooks::commands.scheme_exists', ['path' => $path]));

            return self::FAILURE;
        }

        $stub = (string) $files->get(__DIR__.'/../../stubs/scheme.'.$recipe.'.stub');
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, str_replace(['{{ namespace }}', '{{ class }}'], [self::rootNamespace().'Webhooks\\Schemes', $class], $stub));

        $this->components->info((string) trans('webhooks::commands.scheme_created', ['path' => $path, 'class' => $class]));

        return self::SUCCESS;
    }

    private static function rootNamespace(): string
    {
        try {
            return app()->getNamespace();
        } catch (Throwable) {
            return 'App\\';
        }
    }
}
