<?php

namespace App\Policies;

use App\Models\Dashboard;
use App\Models\User;

class DashboardPolicy
{
    /**
     * Determine whether the user can view any dashboards.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the dashboard.
     */
    public function view(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can create dashboards.
     * Analysts and Admins can create; Viewers cannot.
     */
    public function create(User $user): bool
    {
        return !$user->isViewer();
    }

    /**
     * Determine whether the user can update the dashboard.
     * Admins can update any; Analysts can only update dashboards they own.
     */
    public function update(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->isEditableBy($user);
    }

    /**
     * Determine whether the user can delete the dashboard.
     * Admins can delete any; Analysts can only delete dashboards they own.
     */
    public function delete(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->isEditableBy($user);
    }

    /**
     * Determine whether the user can add, update, or remove widgets on this dashboard.
     * Admins can manage any; Analysts can manage dashboards they own; Viewers cannot.
     */
    public function manageWidgets(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->isEditableBy($user);
    }

    /**
     * Determine whether the user can execute widgets on this dashboard.
     * Viewers can execute company-shared dashboards.
     */
    public function execute(User $user, Dashboard $dashboard): bool
    {
        if ($dashboard->company_id !== $user->company_id) {
            return false;
        }

        if ($user->isViewer()) {
            return $dashboard->visibility === 'company';
        }

        return $dashboard->isAccessibleBy($user);
    }

    /**
     * Determine whether the user can share or change visibility of the dashboard.
     */
    public function share(User $user, Dashboard $dashboard): bool
    {
        return $dashboard->isEditableBy($user);
    }
}
