<?php

namespace App\Policies;

use App\Models\DatabaseConnection;
use App\Models\User;

class DatabaseConnectionPolicy
{
    /**
     * Determine whether the user can list database connections.
     * Admins and Analysts can view connection list.
     */
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isAnalyst();
    }

    /**
     * Determine whether the user can view the database connection.
     */
    public function view(User $user, DatabaseConnection $connection): bool
    {
        return $connection->company_id === $user->company_id && ($user->isAdmin() || $user->isAnalyst());
    }

    /**
     * Determine whether the user can create database connections.
     * Admin only.
     */
    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can test database connections.
     * Admin only.
     */
    public function test(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can delete the database connection.
     * Admin only.
     */
    public function delete(User $user, DatabaseConnection $connection): bool
    {
        return $user->isAdmin() && $connection->company_id === $user->company_id;
    }

    /**
     * Determine whether the user can introspect the schema of the database connection.
     * Admins and Analysts can introspect.
     */
    public function schema(User $user, DatabaseConnection $connection): bool
    {
        return $connection->company_id === $user->company_id && ($user->isAdmin() || $user->isAnalyst());
    }
}
