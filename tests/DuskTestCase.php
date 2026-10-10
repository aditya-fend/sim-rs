<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Laravel\Dusk\TestCase as BaseTestCase;
use Tests\Browser\Pages\LoginPage;

abstract class DuskTestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Browser runs commit their data: Dusk drives a separate HTTP server, so a
     * transaction held by the test process would be rolled back before the
     * browser could read it. Each scenario migrates and seeds first.
     */
    public function prepare(): void
    {
        // Sessions, cache and the rate limiter all share Redis. Leaving the
        // limiter counters behind would let earlier scenarios consume the five
        // login attempts per minute security.md allows, and the failure would
        // look like a broken login rather than a tripped throttle.
        Cache::flush();

        $this->artisan('migrate:fresh', ['--force' => true])->run();
        $this->artisan('db:seed', ['--force' => true])->run();
    }

    /**
     * Dusk runs against the application server, not the test process, so the
     * session has to be established through the UI.
     */
    protected function signIn(string $email): void
    {
        $this->browse(new LoginPage)->signIn($email);
    }

    /**
     * The ward terminals in ui-spec.md run 1024–1920 px wide; desktop-standard
     * is the layout most scenarios assert against.
     *
     * @return array<int, string>
     */
    protected function chrome(): array
    {
        return [
            '--headless=new',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--window-size=1600,1000',
        ];
    }

    /**
     * The browser binary has to be set on the options object. Putting it in
     * chrome() would hand Chrome a literal `--binary=...` argument, which it
     * rejects by exiting at startup.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments($this->chrome());

        // Debian ships `chromium`; chromedriver looks for `google-chrome` by
        // default and reports only "Chrome instance exited" when it is missing.
        $options->setBinary('/usr/bin/chromium');

        $capabilities = DesiredCapabilities::chrome();
        $capabilities->setCapability(ChromeOptions::CAPABILITY, $options);

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? 'http://127.0.0.1:9515',
            $capabilities,
        );
    }
}
