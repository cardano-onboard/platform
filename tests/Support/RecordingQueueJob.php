<?php

namespace Tests\Support;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * A queue message, without a queue behind it.
 *
 * A job only behaves like queued work when it has one of these attached: it is what
 * release() puts the work back onto, and what names the connection whose reservation window
 * the run has to stay inside. Calling handle() with nothing attached is the console case,
 * where a run must carry on to the end because there is no later attempt coming.
 *
 * Deliberately the real abstract Job, so release() is the framework's own implementation
 * rather than something this class decided release should mean. The three methods below are
 * the ones the framework leaves to a transport; this one has no transport, so they answer
 * for the message itself and nothing here fakes anything the test is about. What is
 * recorded is only whether the run asked to be given back, and with what delay.
 */
class RecordingQueueJob extends Job implements JobContract
{
    /** The delay the run asked for, or null when it never asked. */
    public ?int $releaseDelay = null;

    public function __construct(string $connectionName = 'database', private int $attempts = 1)
    {
        $this->connectionName = $connectionName;
    }

    public function release($delay = 0)
    {
        parent::release($delay);

        $this->releaseDelay = (int) $delay;
    }

    public function attempts()
    {
        return $this->attempts;
    }

    public function getJobId()
    {
        return 'recording-queue-job';
    }

    public function getRawBody()
    {
        return '{}';
    }
}
