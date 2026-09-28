<?php

namespace App\Contracts;

/**
 * Somewhere a long piece of work can say which phase it is in and how far through it is.
 *
 * A job that tracks itself through the campaign task framework already answers both of
 * these, so the job is the implementation and hands itself to the service it calls. The
 * service depends on this rather than on the job because the same service runs from the
 * console command and from a test, where there is no task row to write to and the argument
 * is simply null.
 *
 * Naming the phases is the service's job rather than the caller's: the service is what
 * knows where the minutes go, and a caller guessing at that would drift the moment the work
 * changed shape.
 */
interface ReportsTaskProgress
{
    /** The short label the panel shows while this phase runs. */
    public function stage(string $stage): void;

    /**
     * How far through the work this phase is.
     *
     * Leave $total null where nothing counted it. The panel then shows an indeterminate bar
     * rather than a share of a denominator that was invented to fill the argument.
     */
    public function progress(int $done, ?int $total = null): void;
}
