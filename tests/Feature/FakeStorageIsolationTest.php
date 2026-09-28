<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Two test processes in one checkout do not stand on each other's fixtures.
 *
 * Storage::fake() empties the directory it hands back. With no token set it resolves to
 * one fixed path per disk name, so two processes share a directory and each clears the
 * other's files part-way through the other's run. What that produced was a dozen failures
 * in the export tests, in a suite that passes alone, reported as metadata that could not
 * be retrieved or a file written and then missing. It reads as a defect in storage, it
 * moves between runs, and it has cost two investigations from scratch.
 *
 * These assert the isolation directly rather than by running the suite twice, so a change
 * that removes it fails here instead of surfacing later as something that looks unrelated.
 */
class FakeStorageIsolationTest extends TestCase
{
    /**
     * A faked disk lives somewhere named for this process, which is what makes it
     * impossible for two of them to be the same directory.
     */
    public function test_a_faked_disk_is_rooted_somewhere_this_process_owns(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fixture.txt', 'contents');

        $path = Storage::disk('local')->path('fixture.txt');

        $this->assertFileExists($path);
        $this->assertStringContainsString('local_test_'.getmypid(), $path);
        $this->assertStringNotContainsString(
            DIRECTORY_SEPARATOR.'disks'.DIRECTORY_SEPARATOR.'local'.DIRECTORY_SEPARATOR,
            $path,
            'The disk is still under the one path every process shares.'
        );
    }

    /**
     * The failure itself: a fixture written by this process is not somewhere another
     * process is going to empty.
     *
     * Faking a disk clears the directory it resolves to, and with no token that is the
     * one path below. A second process starting up therefore emptied whatever this one
     * had already written there. The clearing is performed here directly, because what
     * matters is not who does it but that this process's fixtures are no longer in reach
     * of it.
     */
    public function test_a_fixture_is_not_written_where_another_process_would_clear_it(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('fixture.txt', 'contents');

        $ours = Storage::disk('local')->path('fixture.txt');
        $this->assertFileExists($ours);

        // The path the framework resolves for this disk when no token is set, which is
        // what every process shared before the fix and what one starting now still empties.
        (new Filesystem)->cleanDirectory(storage_path('framework/testing/disks/local'));

        $this->assertFileExists($ours, "A second process faking the same disk cleared this one's fixtures.");
        $this->assertSame('contents', file_get_contents($ours));
    }

    /**
     * Two disks in one process stay apart as well, which is the behaviour the token must
     * not have cost.
     */
    public function test_two_disks_in_one_process_are_still_separate(): void
    {
        Storage::fake('local');
        Storage::fake('uploads');

        $this->assertNotSame(
            Storage::disk('local')->path(''),
            Storage::disk('uploads')->path(''),
        );
    }

    /**
     * Applied by the base test case, so a test that fakes a disk gets this without asking
     * and a new test cannot be written without it.
     */
    public function test_isolation_is_applied_without_the_test_arranging_it(): void
    {
        $this->assertSame((string) getmypid(), (string) ParallelTesting::token());
    }
}
