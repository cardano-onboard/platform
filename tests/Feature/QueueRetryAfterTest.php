<?php

namespace Tests\Feature;

use App\Jobs\AnalyzeCampaignOnboarding;
use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Tests\TestCase;

/**
 * The reservation window against the jobs that have to fit inside it.
 *
 * A worker reserves a queued message for "retry_after" seconds. When that passes the message
 * goes back on the queue and a second worker starts it, whether or not the first worker is
 * still running it. Every connection shipped at 90 seconds while the onboarding analysis is
 * allowed 900, so that job was handed out roughly ten times per run, and the only thing
 * standing between that and ten concurrent Koios walks was the WithoutOverlapping middleware
 * it happens to carry. A job written without that middleware would have run in duplicate.
 *
 * "tries" does not help here. It bounds retries after a failure is recorded; a reservation
 * that expires is not a failure and was never recorded as one.
 *
 * The timeouts are read out of the job classes rather than listed here, so the next long job
 * is measured against the window without anybody remembering to come back to this file.
 */
class QueueRetryAfterTest extends TestCase
{
    /**
     * Drivers that hold a message for a window and redeliver it once the window passes. SQS
     * is not among them: it reserves the same way, but the window is the queue's visibility
     * timeout in AWS and no value in this repository can set it.
     */
    private const RESERVING_DRIVERS = ['database', 'redis', 'beanstalkd'];

    /**
     * Connections that must be among those checked. Without this, a scan that stopped
     * matching connections would pass every assertion below.
     */
    private const MUST_BE_CHECKED = ['database', 'beanstalkd', 'redis'];

    public function test_every_connection_that_reserves_a_job_waits_longer_than_the_longest_job_runs(): void
    {
        $timeouts = $this->declaredTimeouts();

        $this->assertNotEmpty($timeouts, 'no job in this application declares a timeout, which was not true when this test was written');

        $longest = max($timeouts);
        $slowest = array_search($longest, $timeouts, true);
        $checked = [];

        foreach (config('queue.connections') as $name => $connection) {
            $reserves = in_array($connection['driver'] ?? '', self::RESERVING_DRIVERS, true);

            if (! $reserves && ! array_key_exists('retry_after', $connection)) {
                continue;
            }

            // An absent retry_after is not an absent window. The connectors fall back to 60
            // seconds, which is shorter than every window this test is about.
            $this->assertArrayHasKey(
                'retry_after',
                $connection,
                "the $name connection reserves jobs and sets no retry_after, so it redelivers after the 60 second fallback",
            );

            $this->assertGreaterThan(
                $longest,
                $connection['retry_after'],
                "the $name connection redelivers a job after {$connection['retry_after']} seconds while $slowest is allowed to run for $longest, so a second worker starts it while the first is still running",
            );

            $checked[] = $name;
        }

        foreach (self::MUST_BE_CHECKED as $name) {
            $this->assertContains($name, $checked, "the $name connection was not among the connections checked");
        }
    }

    /**
     * One setting, one default, on every connection that has a window. A per-connection value
     * makes the answer depend on which driver a deployment happens to run, and the job that
     * overruns is the same job either way.
     */
    public function test_the_reservation_window_is_one_setting_with_one_default(): void
    {
        preg_match_all(
            "/'retry_after'\s*=>\s*(.+?),\n/",
            file_get_contents(config_path('queue.php')),
            $declarations,
        );

        $this->assertNotEmpty($declarations[1], 'config/queue.php declares no retry_after');

        foreach ($declarations[1] as $expression) {
            $this->assertSame(
                "(int) env('QUEUE_RETRY_AFTER', 1200)",
                trim($expression),
                'every retry_after in config/queue.php reads the same setting with the same default',
            );
        }

        $configured = array_filter(
            config('queue.connections'),
            fn (array $connection) => array_key_exists('retry_after', $connection),
        );

        $this->assertCount(
            count($configured),
            $declarations[1],
            'a connection resolves a retry_after that config/queue.php does not spell out, so the setting above does not reach it',
        );
    }

    /**
     * The setting, not just its spelling. A connection can name the variable and still be
     * read past, and an operator who raises the window for a long job has no way of seeing
     * that one connection kept the old one until a job runs twice.
     */
    public function test_the_setting_reaches_every_connection_that_has_a_window(): void
    {
        // The superglobals rather than putenv(): env() reads the server and environment
        // adapters first, so on a machine whose .env sets this variable a putenv() value is
        // never reached and the override would silently do nothing.
        $restore = [
            '_SERVER' => $_SERVER['QUEUE_RETRY_AFTER'] ?? null,
            '_ENV' => $_ENV['QUEUE_RETRY_AFTER'] ?? null,
        ];

        $_SERVER['QUEUE_RETRY_AFTER'] = '600';
        $_ENV['QUEUE_RETRY_AFTER'] = '600';

        try {
            // Re-read rather than config(), which holds what was resolved when the
            // application booted and cannot be asked the question again.
            $connections = (require config_path('queue.php'))['connections'];
        } finally {
            foreach (['_SERVER', '_ENV'] as $global) {
                if ($restore[$global] === null) {
                    unset($GLOBALS[$global]['QUEUE_RETRY_AFTER']);
                } else {
                    $GLOBALS[$global]['QUEUE_RETRY_AFTER'] = $restore[$global];
                }
            }
        }

        $checked = [];

        foreach ($connections as $name => $connection) {
            if (! array_key_exists('retry_after', $connection)) {
                continue;
            }

            $this->assertSame(600, $connection['retry_after'], "the $name connection does not take its window from QUEUE_RETRY_AFTER");

            $checked[] = $name;
        }

        foreach (self::MUST_BE_CHECKED as $name) {
            $this->assertContains($name, $checked, "the $name connection has no window to set");
        }

        // An override left behind would follow every later test in this process.
        $this->assertSame($restore['_SERVER'], $_SERVER['QUEUE_RETRY_AFTER'] ?? null);
        $this->assertSame($restore['_ENV'], $_ENV['QUEUE_RETRY_AFTER'] ?? null);
    }

    /**
     * The two templates an operator starts from. A template that hands out a shorter window
     * than the code defaults to reintroduces the bug on every box copied from it, and the
     * Docker template is the one the published edition ships with a worker already running.
     */
    public function test_the_environment_templates_hand_out_the_window_the_code_declares(): void
    {
        preg_match(
            "/'retry_after'\s*=>\s*\(int\) env\('QUEUE_RETRY_AFTER',\s*(\d+)\)/",
            file_get_contents(config_path('queue.php')),
            $declared,
        );

        $this->assertNotEmpty($declared, 'config/queue.php declares no default window to compare the templates against');

        foreach (['.env.example', '.env.docker'] as $template) {
            preg_match_all('/^QUEUE_RETRY_AFTER=(.*)$/m', file_get_contents(base_path($template)), $settings);

            $this->assertCount(1, $settings[1], "$template should set QUEUE_RETRY_AFTER once");

            $this->assertSame(
                $declared[1],
                trim($settings[1][0]),
                "$template starts an operator on a different window from the one the code defaults to",
            );
        }
    }

    /**
     * The scan itself. A reader that quietly found nothing would make the assertion above
     * true of an application with no jobs in it.
     */
    public function test_the_scan_reaches_every_queued_class_in_the_application(): void
    {
        $classes = $this->queuedClasses();

        $this->assertArrayHasKey(AnalyzeCampaignOnboarding::class, $classes, 'the scan missed a job sitting in app/Jobs');

        $this->assertGreaterThanOrEqual(
            count(glob(app_path('Jobs/*.php'))),
            count($classes),
            'the scan found fewer queued classes than there are files in app/Jobs',
        );
    }

    /**
     * Seconds each queued class allows itself, keyed by class. Classes that declare no
     * timeout are absent: they run under the worker's own timeout, which is shorter than
     * every window here.
     */
    private function declaredTimeouts(): array
    {
        $timeouts = [];

        foreach ($this->queuedClasses() as $class => $source) {
            $declared = (new ReflectionClass($class))->getDefaultProperties()['timeout'] ?? null;

            if ($declared !== null) {
                $this->assertIsInt($declared, "$class declares a timeout that is not a number of seconds");

                $timeouts[$class] = $declared;
            }

            // A timeout the constructor works out reaches the queue payload exactly as a
            // declared one does, and reflection cannot see its value. The expression is read
            // from the source instead, and one this test cannot evaluate fails rather than
            // being passed over, because a timeout nothing can read is the timeout that
            // reintroduces the bug.
            preg_match_all('/\$this->timeout\s*=\s*([^;]+);/', $source, $assignments);

            foreach ($assignments[1] as $expression) {
                $timeouts[$class] = max($timeouts[$class] ?? 0, $this->seconds($expression, $class));
            }
        }

        return $timeouts;
    }

    /**
     * A timeout expression as a number of seconds: a literal, or a config key this
     * application can resolve.
     */
    private function seconds(string $expression, string $class): int
    {
        $expression = trim($expression);

        if (preg_match('/^\(int\)\s*(.+)$/', $expression, $cast)) {
            $expression = trim($cast[1]);
        }

        if (preg_match('/^\d[\d_]*$/', $expression)) {
            return (int) str_replace('_', '', $expression);
        }

        if (preg_match('/^config\(\s*\'([^\']+)\'\s*(?:,\s*(\d+)\s*)?\)$/', $expression, $call)) {
            $value = config($call[1], isset($call[2]) ? (int) $call[2] : null);

            $this->assertIsNumeric(
                $value,
                "$class takes its timeout from config('{$call[1]}'), which does not resolve to a number of seconds",
            );

            return (int) $value;
        }

        $this->fail(
            "$class sets its timeout to '$expression', which this test cannot turn into seconds. "
            .'Give it a plain number or a config key, or teach this test the shape: an unread timeout is one nothing keeps under the reservation window.'
        );
    }

    /**
     * Every queued class under app/, keyed by class name, with its source.
     */
    private function queuedClasses(): array
    {
        $classes = [];

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = file_get_contents($file->getPathname());

            if (! str_contains($source, 'ShouldQueue')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(app_path().DIRECTORY_SEPARATOR), -strlen('.php'));
            $class = 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

            if (! class_exists($class)) {
                // An interface or trait naming ShouldQueue is not a job. A class the
                // autoloader cannot find under its own path is a real break, and saying
                // nothing about it would leave its timeout unmeasured.
                $this->assertTrue(
                    interface_exists($class) || trait_exists($class),
                    $file->getPathname()." names ShouldQueue and does not autoload as $class",
                );

                continue;
            }

            if (! is_subclass_of($class, ShouldQueue::class)) {
                continue;
            }

            $classes[$class] = $source;
        }

        ksort($classes);

        return $classes;
    }
}
