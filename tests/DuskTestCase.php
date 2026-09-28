<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverBy;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;
use Throwable;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Id of the element the input probe clicks.
     */
    private const INPUT_PROBE_ID = 'dusk-input-probe';

    /**
     * Prepare for Dusk test execution.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        // Skip starting local ChromeDriver when using a remote Selenium instance
        if (isset($_ENV['DUSK_DRIVER_URL']) || env('DUSK_DRIVER_URL')) {
            return;
        }

        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->discardBrowsersThatNoLongerReceiveInput();
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://localhost:9515',
            DesiredCapabilities::chrome()
                ->setCapability(ChromeOptions::CAPABILITY, $options)
                // Capture the browser console so tests can assert on CSP violations.
                ->setCapability('goog:loggingPrefs', ['browser' => 'ALL'])
        );
    }

    /**
     * Determine whether a browser still delivers driver-synthesised input to the page.
     *
     * A Chrome session can stop delivering synthesised mouse and keyboard input while
     * everything else about it keeps working: navigation succeeds, scripts run, the page
     * renders, and a click is accepted by the driver without error. The page simply never
     * receives an event. Nothing in the WebDriver protocol reports this, so a test that
     * clicks and then waits sees only its wait expire, which reads as a broken feature.
     *
     * The probe distinguishes the two by clicking an element whose only job is to record
     * that it was clicked. It covers the top left corner, sits above everything else, and
     * is removed again afterwards.
     */
    public static function browserReceivesInput(Browser $browser): bool
    {
        $install = sprintf(<<<'JS'
            if (! document.body) {
                return false;
            }

            var stale = document.getElementById('%1$s');

            if (stale) {
                stale.remove();
            }

            window.duskInputProbeSeen = false;

            var probe = document.createElement('div');
            probe.id = '%1$s';
            probe.setAttribute('style', 'position:fixed;left:0;top:0;width:64px;height:64px;z-index:2147483647');
            probe.addEventListener('mousedown', function () {
                window.duskInputProbeSeen = true;
            });
            document.body.appendChild(probe);

            return true;
        JS, self::INPUT_PROBE_ID);

        try {
            $installed = $browser->driver->executeScript($install);
        } catch (Throwable) {
            return false;
        }

        // No document to probe, so there is nothing to conclude either way.
        if ($installed !== true) {
            return true;
        }

        try {
            $browser->driver->findElement(WebDriverBy::id(self::INPUT_PROBE_ID))->click();

            $received = $browser->driver->executeScript('return window.duskInputProbeSeen === true;') === true;
        } catch (Throwable) {
            $received = false;
        }

        try {
            $browser->driver->executeScript(sprintf(
                "var probe = document.getElementById('%s'); if (probe) { probe.remove(); }",
                self::INPUT_PROBE_ID
            ));
        } catch (Throwable) {
            // The probe goes away with the page, so leaving it behind costs nothing.
        }

        return $received;
    }

    /**
     * Fail the test unless the browser can still deliver synthesised input to the page.
     */
    protected function assertBrowserReceivesInput(Browser $browser): void
    {
        $this->assertTrue(
            static::browserReceivesInput($browser),
            'The browser session stopped delivering synthesised input, so no click or keystroke could reach the page.'
        );
    }

    /**
     * Quit any reused browser that has stopped delivering input to the page.
     *
     * Dusk reuses one browser for every test in a class, and a session that has stopped
     * accepting input never recovers: reloading the page, resizing the window, switching
     * window handles and opening a fresh window all leave it deaf. Every test that follows
     * in that class is then either a false failure or, worse, a pass that proves nothing,
     * because a test asserting that a page did not change is satisfied by a click going
     * nowhere. Quitting the browser here empties the pool, and Dusk builds a fresh session
     * for the next test.
     *
     * The probe runs on a blank page so it cannot disturb what the previous test left on
     * screen. Every browser test navigates before it asserts anything.
     */
    private function discardBrowsersThatNoLongerReceiveInput(): void
    {
        if (count(static::$browsers) === 0) {
            return;
        }

        static::$browsers = Collection::make(static::$browsers)
            ->filter(function (Browser $browser) {
                try {
                    $browser->driver->get('about:blank');
                    $usable = static::browserReceivesInput($browser);
                } catch (Throwable) {
                    $usable = false;
                }

                if ($usable) {
                    return true;
                }

                fwrite(STDERR, PHP_EOL.'  Dusk: browser session stopped accepting input; starting a new one.'.PHP_EOL);

                try {
                    $browser->quit();
                } catch (Throwable) {
                    // The session is already gone, so there is nothing left to release.
                }

                return false;
            })
            ->values();
    }
}
