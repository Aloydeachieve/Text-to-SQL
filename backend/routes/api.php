<?php

use App\Http\Controllers\Api\v1\AuthController;
use App\Http\Controllers\Api\v1\CompanyMemberController;
use App\Http\Controllers\Api\v1\DashboardController;
use App\Http\Controllers\Api\v1\DatabaseConnectionController;
use App\Http\Controllers\Api\v1\HistoryController;
use App\Http\Controllers\Api\v1\QueryController;
use App\Http\Controllers\Api\v1\SavedQueryController;
use App\Http\Controllers\Api\v1\SchemaController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Public Authentication Endpoints
    Route::post('/auth/register', [AuthController::class, 'register']);
    Route::post('/auth/login', [AuthController::class, 'login']);

    // Existing Demo Endpoints (Preserved for Demo Mode & Backward Compatibility)
    Route::post('/query', QueryController::class);
    Route::get('/history', HistoryController::class);
    Route::get('/schema', SchemaController::class);

    // Protected Multi-Tenant Endpoints
    Route::middleware('auth:sanctum')->group(function () {
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
        Route::delete('/database-connections/{id}', [DatabaseConnectionController::class, 'destroy']);
        Route::get('/database-connections/{id}/schema', [DatabaseConnectionController::class, 'schema']);

        // Reusable Business Intelligence & Saved Queries
        Route::get('/saved-queries', [SavedQueryController::class, 'index']);
        Route::post('/saved-queries', [SavedQueryController::class, 'store']);
        Route::get('/saved-queries/{id}', [SavedQueryController::class, 'show']);
        Route::match(['put', 'patch'], '/saved-queries/{id}', [SavedQueryController::class, 'update']);
        Route::delete('/saved-queries/{id}', [SavedQueryController::class, 'destroy']);
        Route::post('/saved-queries/{id}/execute', [SavedQueryController::class, 'execute']);

        // Dashboards & Analytics Widgets
        Route::get('/dashboards', [DashboardController::class, 'index']);
        Route::post('/dashboards', [DashboardController::class, 'store']);
        Route::get('/dashboards/{id}', [DashboardController::class, 'show']);
        Route::match(['put', 'patch'], '/dashboards/{id}', [DashboardController::class, 'update']);
        Route::delete('/dashboards/{id}', [DashboardController::class, 'destroy']);
        Route::post('/dashboards/{id}/widgets', [DashboardController::class, 'addWidget']);
        Route::match(['put', 'patch'], '/dashboards/{id}/widgets/{widgetId}', [DashboardController::class, 'updateWidget']);
        Route::delete('/dashboards/{id}/widgets/{widgetId}', [DashboardController::class, 'deleteWidget']);
        Route::post('/dashboards/{id}/execute', [DashboardController::class, 'execute']);
        Route::get('/dashboards/{id}/export/csv', [DashboardController::class, 'exportCsv']);
        Route::get('/dashboards/{id}/export/summary', [DashboardController::class, 'exportSummary']);
    });
});
