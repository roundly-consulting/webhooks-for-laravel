<?php

declare(strict_types=1);

namespace RoundlyConsulting\Webhooks\Tests\Install;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\File;
use RoundlyConsulting\Webhooks\Tests\TestCase;

/**
 * `webhooks:install` publishes into the host's config/ and database/ (`webhooks:make-scheme` into app/): here a throwaway directory
 * per test, set before the providers boot — never the shared Testbench skeleton.
 */
abstract class InstallTestCase extends TestCase
{
    protected string $sandbox = '';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $this->sandbox = sys_get_temp_dir().'/webhooks-install-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->sandbox.'/config');
        File::ensureDirectoryExists($this->sandbox.'/database/migrations');

        $app->useConfigPath($this->sandbox.'/config');
        $app->useDatabasePath($this->sandbox.'/database');
        $app->useAppPath($this->sandbox.'/app');
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '') {
            File::deleteDirectory($this->sandbox);
        }

        parent::tearDown();
    }
}
