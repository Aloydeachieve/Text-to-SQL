<?php

namespace App\Policies;

use App\Models\User;

class CompanyMemberPolicy
{
    /**
     * Determine whether the user can view the company member directory.
     * All company members can view team directory.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can add new members.
     * Admin only.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can update a member's role or info.
     * Admin only.
     */
    public function update(User $user, User $targetUser): bool
    {
        return $user->isAdmin() && $user->company_id === $targetUser->company_id;
    }

    /**
     * Determine whether the user can remove a member.
     * Admin only.
     */
    public function delete(User $user, User $targetUser): bool
    {
        return $user->isAdmin() && $user->company_id === $targetUser->company_id;
    }
}
