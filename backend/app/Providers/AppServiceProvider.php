<?php

namespace App\Providers;

use App\Models\Dashboard;
use App\Models\DatabaseConnection;
use App\Models\SavedQuery;
use App\Policies\DashboardPolicy;
use App\Policies\DatabaseConnectionPolicy;
use App\Policies\SavedQueryPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(SavedQuery::class, SavedQueryPolicy::class);
        Gate::policy(Dashboard::class, DashboardPolicy::class);
        Gate::policy(DatabaseConnection::class, DatabaseConnectionPolicy::class);
    }
}
