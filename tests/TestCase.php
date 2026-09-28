<?php

namespace Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\ParallelTesting;

abstract class TestCase extends BaseTestCase
{
    /** Whether this process has already arranged to clear up after itself. */
    private static bool $fakeDiskCleanupRegistered = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->giveThisProcessItsOwnFakeDisks();
    }

    protected function tearDown(): void
    {
        // Force any leaked PendingDispatch destructors to fire now, while this
        // test's app + DB are still alive. Without this, GC can run during the
        // next test's setUp() and try to query a not-yet-migrated DB.
        gc_collect_cycles();

        parent::tearDown();
    }

    /**
     * Assert what came back out of a JSON column, without asserting the engine's key order.
     *
     * A JSON object carries no order for its keys, and the two engines this suite runs on
     * disagree about what to do with the order they were handed. MySQL's native JSON type
     * normalises object keys on the way in, sorting them by length and then by bytes;
     * SQLite stores the text as written and gives back the order it received. Comparing the
     * arrays as they arrive asserts that choice rather than the record, which passes on
     * SQLite and fails on the engine production actually uses.
     *
     * Everything else is still compared strictly: the number of elements, the order of list
     * elements, the exact set of keys on each object with nothing extra and nothing missing,
     * and every value with its own type, so a count that came back as the string "3" still
     * fails. Only the order of object keys is let go, and that is the one part nothing
     * reads, because every consumer of these columns takes the values out by name.
     */
    protected function assertSameJson(array $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsArray($actual, $message);

        $this->assertSame(
            $this->withSortedObjectKeys($expected),
            $this->withSortedObjectKeys($actual),
            $message
        );
    }

    /** The same structure with every object's keys sorted and every list left in its order. */
    private function withSortedObjectKeys(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->withSortedObjectKeys($item);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * Point Storage::fake() at directories belonging to this process alone.
     *
     * Storage::fake() empties the directory it is about to hand back, and with no token
     * set it resolves to one fixed path per disk name inside the application's storage
     * directory. Two test processes in one checkout therefore share that directory, and
     * each clears the other's fixtures part-way through the other's run. It surfaces as
     * metadata that cannot be retrieved, or a file written and then missing, which reads
     * as a defect in storage rather than as one process standing on another, and the
     * failures move between runs because they depend on which process reached which line
     * first.
     *
     * The framework already appends a token to that path wherever one is set, so naming
     * this process is the whole of the fix. It is done here so that no individual test
     * has to remember, and the resolver is set on every test because it lives on the
     * application, which is rebuilt between them.
     *
     * Only the faked storage root changes. Everything else the token feeds, the test
     * database name, the cache prefix and the compiled view path, is reached through
     * callbacks the framework runs only when it is genuinely driving a parallel run,
     * which it decides by a separate environment variable that nothing here sets.
     */
    private function giveThisProcessItsOwnFakeDisks(): void
    {
        ParallelTesting::resolveTokenUsing(static fn () => self::fakeDiskToken());

        $this->removeThisProcessesFakeDisksOnShutdown();
    }

    /**
     * This process's name for the directories it fakes disks under.
     *
     * A token set from outside wins, so running the suite through a parallel runner keeps
     * that runner's own numbering rather than acquiring a second scheme alongside it.
     */
    private static function fakeDiskToken(): string
    {
        return (string) ($_SERVER['TEST_TOKEN'] ?? getmypid());
    }

    /**
     * Delete the directories this process faked disks under, once it is finished with
     * them.
     *
     * A directory per process means a new one per run rather than one that is reused, and
     * nothing else removes them. They hold fixtures written by tests, they sit inside the
     * application's storage directory, and left alone they accumulate one set per run
     * forever.
     */
    private function removeThisProcessesFakeDisksOnShutdown(): void
    {
        if (self::$fakeDiskCleanupRegistered) {
            return;
        }

        self::$fakeDiskCleanupRegistered = true;

        // Storage::fake() builds its root as <disks>/<disk name>_test_<token>, so this is
        // every disk this process faked and nothing belonging to any other.
        $pattern = storage_path('framework/testing/disks').DIRECTORY_SEPARATOR.'*_test_'.self::fakeDiskToken();

        register_shutdown_function(static function () use ($pattern) {
            $filesystem = new Filesystem;

            foreach (glob($pattern, GLOB_ONLYDIR) ?: [] as $directory) {
                $filesystem->deleteDirectory($directory);
            }
        });
    }
}
