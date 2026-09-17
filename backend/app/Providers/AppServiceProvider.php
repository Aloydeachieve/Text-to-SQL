<?php

namespace App\Providers;

use App\Models\Dashboard;
use App\Models\DatabaseConnection;
use App\Models\SavedQuery;
use App\Policies\DashboardPolicy;
use App\Policies\DatabaseConnectionPolicy;
use App\Policies\SavedQueryPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // Phase 10: Named Rate Limiters
        RateLimiter::for('api', function (Request $request) {
            $limit = (int) config('reliability.rate_limits.api', 120);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('ai-generation', function (Request $request) {
            $limit = (int) config('reliability.rate_limits.ai', 20);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('query-execution', function (Request $request) {
            $limit = (int) config('reliability.rate_limits.query', 30);
            return Limit::perMinute($limit)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            $limit = (int) config('reliability.rate_limits.auth', 10);
            return Limit::perMinute($limit)->by($request->ip());
        });
    }
}
