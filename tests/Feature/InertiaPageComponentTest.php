<?php

namespace Tests\Feature;

use Inertia\ComponentNotFoundException;
use Inertia\Inertia;
use Tests\TestCase;

/**
 * Feature tests render the page shell without a built frontend, so nothing there fails on a
 * component the frontend does not have. These keep that check in place at render time.
 */
class InertiaPageComponentTest extends TestCase
{
    public function test_rendering_a_component_that_does_not_exist_fails(): void
    {
        $this->expectException(ComponentNotFoundException::class);

        Inertia::render('Campaign/DoesNotExist');
    }

    public function test_rendering_a_component_that_exists_succeeds(): void
    {
        $this->assertSame(200, Inertia::render('Welcome')->toResponse(request())->getStatusCode());
    }
}
