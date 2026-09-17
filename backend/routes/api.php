<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\CompanyMemberController;
use App\Http\Controllers\Api\v1\DashboardController;
use App\Http\Controllers\Api\v1\DatabaseConnectionController;
use App\Http\Controllers\Api\v1\HistoryController;
use App\Http\Controllers\Api\v1\QueryController;
use App\Http\Controllers\Api\v1\SavedQueryController;
use App\Http\Controllers\Api\v1\SchemaController;
use App\Http\Controllers\Api\v1\SemanticController;
use Illuminate\Support\Facades\Route;

// Phase 10: Operational Health & Readiness Probes (Public)
Route::get('/health', [HealthController::class, 'health']);
Route::get('/ready', [HealthController::class, 'ready']);

Route::prefix('v1')->group(function () {
    // Phase 10 Health Aliases under v1
    Route::get('/health', [HealthController::class, 'health']);
    Route::get('/ready', [HealthController::class, 'ready']);

    // Public Authentication Endpoints with Rate Limiting
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');

    // Existing Demo Endpoints (Preserved for Demo Mode & Backward Compatibility)
    Route::post('/query', QueryController::class)->middleware('throttle:query-execution');
    Route::get('/history', HistoryController::class);
    Route::get('/schema', SchemaController::class);

    // Protected Multi-Tenant Endpoints
    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // Company Membership & Roles
        Route::get('/company/members', [CompanyMemberController::class, 'index']);
        Route::post('/company/members', [CompanyMemberController::class, 'store']);
        Route::match(['put', 'patch'], '/company/members/{id}', [CompanyMemberController::class, 'update']);
        Route::delete('/company/members/{id}', [CompanyMemberController::class, 'destroy']);

        // Tenant Database Connection Management
        Route::post('/database-connections/test', [DatabaseConnectionController::class, 'test']);
        Route::get('/database-connections', [DatabaseConnectionController::class, 'index']);
        Route::post('/database-connections', [DatabaseConnectionController::class, 'store']);
        Route::get('/database-connections/{id}', [DatabaseConnectionController::class, 'show']);
        Route::get('/database-connections/{id}/health', [DatabaseConnectionController::class, 'health']);
        Route::delete('/database-connections/{id}', [DatabaseConnectionController::class, 'destroy']);
        Route::get('/database-connections/{id}/schema', [DatabaseConnectionController::class, 'schema']);

        // Reusable Business Intelligence & Saved Queries
        Route::get('/saved-queries', [SavedQueryController::class, 'index']);
        Route::post('/saved-queries', [SavedQueryController::class, 'store']);
        Route::get('/saved-queries/{id}', [SavedQueryController::class, 'show']);
        Route::match(['put', 'patch'], '/saved-queries/{id}', [SavedQueryController::class, 'update']);
        Route::delete('/saved-queries/{id}', [SavedQueryController::class, 'destroy']);
        Route::post('/saved-queries/{id}/execute', [SavedQueryController::class, 'execute'])->middleware('throttle:query-execution');

        // Dashboards & Analytics Widgets
        Route::get('/dashboards', [DashboardController::class, 'index']);
        Route::post('/dashboards', [DashboardController::class, 'store']);
        Route::get('/dashboards/{id}', [DashboardController::class, 'show']);
        Route::match(['put', 'patch'], '/dashboards/{id}', [DashboardController::class, 'update']);
        Route::delete('/dashboards/{id}', [DashboardController::class, 'destroy']);
        Route::post('/dashboards/{id}/widgets', [DashboardController::class, 'addWidget']);
        Route::match(['put', 'patch'], '/dashboards/{id}/widgets/{widgetId}', [DashboardController::class, 'updateWidget']);
        Route::delete('/dashboards/{id}/widgets/{widgetId}', [DashboardController::class, 'deleteWidget']);
        Route::post('/dashboards/{id}/execute', [DashboardController::class, 'execute'])->middleware('throttle:query-execution');
        Route::get('/dashboards/{id}/export/csv', [DashboardController::class, 'exportCsv']);
        Route::get('/dashboards/{id}/export/summary', [DashboardController::class, 'exportSummary']);

        // Phase 9: Business Semantic Layer, Metric Definitions, Terms & Classifications
        Route::get('/semantic/metrics', [SemanticController::class, 'indexMetrics']);
        Route::post('/semantic/metrics', [SemanticController::class, 'storeMetric']);
        Route::get('/semantic/metrics/{id}', [SemanticController::class, 'showMetric']);
        Route::match(['put', 'patch'], '/semantic/metrics/{id}', [SemanticController::class, 'updateMetric']);
        Route::delete('/semantic/metrics/{id}', [SemanticController::class, 'destroyMetric']);

        Route::get('/semantic/terms', [SemanticController::class, 'indexTerms']);
        Route::post('/semantic/terms', [SemanticController::class, 'storeTerm']);
        Route::match(['put', 'patch'], '/semantic/terms/{id}', [SemanticController::class, 'updateTerm']);
        Route::delete('/semantic/terms/{id}', [SemanticController::class, 'destroyTerm']);

        Route::get('/semantic/table-classifications', [SemanticController::class, 'indexClassifications']);
        Route::post('/semantic/table-classifications', [SemanticController::class, 'storeClassification']);
        Route::delete('/semantic/table-classifications/{id}', [SemanticController::class, 'destroyClassification']);

        Route::get('/semantic/lineage', [SemanticController::class, 'lineage']);
        Route::get('/semantic/drift/{savedQueryId}', [SemanticController::class, 'detectDrift']);
    });
});
