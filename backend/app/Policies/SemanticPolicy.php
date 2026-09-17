<?php

namespace App\Policies;

use App\Models\User;

class SemanticPolicy
{
    /**
     * Determine whether the user can view semantic definitions.
     * All company members can view metrics, terms, and table classifications.
     */
    public function viewAny(User $user): bool
    {
        return !empty($user->company_id);
    }

    /**
     * Determine whether the user can view a specific semantic entity.
     */
    public function view(User $user, object $entity): bool
    {
        return !empty($user->company_id) && $user->company_id === $entity->company_id;
    }

    /**
     * Determine whether the user can create semantic entities.
     * Restricted to Admins.
     */
    public function create(User $user): bool
    {
        return !empty($user->company_id) && $user->isAdmin();
    }

    /**
     * Determine whether the user can update semantic entities.
     * Restricted to Admins of the same company.
     */
    public function update(User $user, object $entity): bool
    {
        return !empty($user->company_id) && $user->isAdmin() && $user->company_id === $entity->company_id;
    }

    /**
     * Determine whether the user can delete semantic entities.
     * Restricted to Admins of the same company.
     */
    public function delete(User $user, object $entity): bool
    {
        return !empty($user->company_id) && $user->isAdmin() && $user->company_id === $entity->company_id;
    }
}
