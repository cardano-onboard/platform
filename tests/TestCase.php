<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        // Force any leaked PendingDispatch destructors to fire now, while this
        // test's app + DB are still alive. Without this, GC can run during the
        // next test's setUp() and try to query a not-yet-migrated DB.
        gc_collect_cycles();

        parent::tearDown();
    }
}
