<?php

namespace App\Support;

use App\Jobs\AnalyzeCampaignOnboarding;
use App\Jobs\CheckClaims;
use App\Jobs\GenerateQrExport;
use App\Jobs\ProcessUploadedCodes;

/**
 * The task types this deployment knows how to run, and what a finished run changes.
 *
 * The map lives here rather than on the model so that adding a type is one line next to
 * the job it belongs to, and the model stays clear of the jobs. An unknown type is not an
 * error: a row written before a deployment removed its job still has to be reportable, it
 * simply has nothing for the page to refresh.
 */
final class CampaignTaskTypes
{
    /**
     * Task type to the job that runs it.
     *
     * @return array<string, class-string>
     */
    public static function map(): array
    {
        return [
            ProcessUploadedCodes::TASK_TYPE => ProcessUploadedCodes::class,
            GenerateQrExport::TASK_TYPE => GenerateQrExport::class,
            AnalyzeCampaignOnboarding::TASK_TYPE => AnalyzeCampaignOnboarding::class,
            CheckClaims::TASK_TYPE => CheckClaims::class,
        ];
    }

    /**
     * The Inertia props a finished run of this type invalidates.
     *
     * @return list<string>
     */
    public static function reloads(string $type): array
    {
        $job = self::map()[$type] ?? null;

        if ($job === null || ! method_exists($job, 'reloads')) {
            return [];
        }

        return $job::reloads();
    }
}
