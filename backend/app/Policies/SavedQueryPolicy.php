<?php

namespace App\Policies;

use App\Models\SavedQuery;
use App\Models\User;

class SavedQueryPolicy
{
    /**
     * Determine whether the user can view any saved queries.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the saved query.
     */
    public function view(User $user, SavedQuery $savedQuery): bool
    {
        return $savedQuery->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can create saved queries.
     * Analysts and Admins can create; Viewers cannot.
     */
    public function create(User $user): bool
    {
        return !$user->isViewer();
    }

    /**
     * Determine whether the user can update the saved query.
     * Admins can update any; Analysts can only update queries they own.
     */
    public function update(User $user, SavedQuery $savedQuery): bool
    {
        return $savedQuery->isEditableBy($user);
    }

    /**
     * Determine whether the user can delete the saved query.
     * Admins can delete any; Analysts can only delete queries they own.
     */
    public function delete(User $user, SavedQuery $savedQuery): bool
    {
        return $savedQuery->isEditableBy($user);
    }

    /**
     * Determine whether the user can execute the saved query.
     * Viewers can only execute company-shared queries.
     */
    public function execute(User $user, SavedQuery $savedQuery): bool
    {
        if ($savedQuery->company_id !== $user->company_id) {
            return false;
        }

        if ($user->isViewer()) {
            return $savedQuery->visibility === 'company';
        }

        return $savedQuery->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can share or change visibility of the saved query.
     */
    public function share(User $user, SavedQuery $savedQuery): bool
    {
        return $savedQuery->isEditableBy($user);
    }
}
