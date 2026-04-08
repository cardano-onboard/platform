<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class CampaignPolicy {

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool {
        Log::debug("Campaign viewAny called", compact('user'));

        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Campaign $campaign): bool {
        Log::debug("Campaign view called", compact('user', 'campaign'));
        if ($user->id === $campaign->user_id) {
            return true;
        }

        Log::error("Campaign View Failure: {$user->id} !== {$campaign->user_id}");

        return false;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Campaign $campaign): bool {
        Log::debug("Campaign update called", compact('user', 'campaign'));

        return $user->id === $campaign->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Campaign $campaign): bool {
        Log::debug("Campaign delete called", compact('user', 'campaign'));

        return $user->id === $campaign->user_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Campaign $campaign): bool {
        Log::debug("Campaign restore called", compact('user', 'campaign'));

        return $user->id === $campaign->user_id;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Campaign $campaign): bool {
        Log::debug("Campaign forceDelete called", compact('user', 'campaign'));

        return $user->id === $campaign->user_id;
    }
}
