<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\SavedQuery;
use App\Services\SavedQueryExecutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    protected SavedQueryExecutionService $executionService;

    public function __construct(SavedQueryExecutionService $executionService)
    {
        $this->executionService = $executionService;
    }

    /**
     * List all dashboards for the authenticated user's company according to visibility.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Dashboard::visibleTo($request->user())
            ->with(['user:id,name,email'])
            ->withCount('widgets')
            ->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $dashboards = $query->get();

        return response()->json([
            'success' => true,
            'data' => $dashboards,
        ]);
    }

    /**
     * Store a new dashboard for the authenticated user's company (Admin and Analyst).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->isViewer()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $companyId = $user->company_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'visibility' => 'nullable|string|in:private,company',
        ]);

        $visibility = $validated['visibility'] ?? 'private';

        $dashboard = Dashboard::create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'visibility' => $visibility,
        ]);

        if ($visibility === 'company') {
            \App\Models\AuditLog::record(
                $companyId,
                $user->id,
                'dashboard_shared',
                'dashboard',
                $dashboard->id,
                ['name' => $dashboard->name]
            );
        }

        $dashboard->load(['user:id,name,email']);
        $dashboard->loadCount('widgets');

        return response()->json([
            'success' => true,
            'data' => $dashboard,
        ], 201);
    }

    /**
     * Show details of a single dashboard including its widgets.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)
            ->with([
                'user:id,name,email',
                'widgets.savedQuery.databaseConnection:id,name,driver,status',
                'widgets.savedQuery.user:id,name,email',
            ])
            ->withCount('widgets')
            ->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isAccessibleBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $dashboard,
        ]);
    }

    /**
     * Update an existing dashboard.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isEditableBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'visibility' => 'sometimes|required|string|in:private,company',
        ]);

        if (isset($validated['visibility']) && $validated['visibility'] !== $dashboard->visibility) {
            $action = $validated['visibility'] === 'company' ? 'dashboard_shared' : 'dashboard_unshared';
            \App\Models\AuditLog::record(
                $companyId,
                $user->id,
                $action,
                'dashboard',
                $dashboard->id,
                ['name' => $dashboard->name, 'old_visibility' => $dashboard->visibility, 'new_visibility' => $validated['visibility']]
            );
        }

        $dashboard->update($validated);
        $dashboard->load(['user:id,name,email']);
        $dashboard->loadCount('widgets');

        return response()->json([
            'success' => true,
            'data' => $dashboard,
        ]);
    }

    /**
     * Delete a dashboard and its widgets.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isEditableBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $dashboard->delete();

        return response()->json([
            'success' => true,
            'message' => 'Dashboard deleted successfully.',
        ]);
    }

    /**
     * Add a saved query as a widget to the dashboard.
     */
    public function addWidget(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isEditableBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $validated = $request->validate([
            'saved_query_id' => 'required|integer',
            'title' => 'nullable|string|max:255',
            'visualization_type' => 'nullable|string|in:table,bar,line,metric,none',
            'position' => 'nullable|integer|min:0',
            'width' => 'nullable|integer|min:1|max:4',
            'height' => 'nullable|integer|min:1|max:4',
        ]);

        // Strict Tenant & Visibility Isolation: Saved Query MUST belong to the company AND be accessible
        $savedQuery = SavedQuery::where('company_id', $companyId)
            ->where('id', $validated['saved_query_id'])
            ->first();

        if (!$savedQuery || !$savedQuery->isAccessibleBy($user)) {
            return response()->json([
                'message' => 'Saved query not found or unauthorized.'
            ], 404);
        }

        // Determine position if not explicitly specified
        $position = $validated['position'] ?? ($dashboard->widgets()->max('position') !== null
            ? $dashboard->widgets()->max('position') + 1
            : 0);

        // Fallback visualization type to saved query preference if not provided
        $vizType = $validated['visualization_type'] ?? ($savedQuery->result_visualization_type ?: 'bar');

        $widget = DashboardWidget::create([
            'dashboard_id' => $dashboard->id,
            'saved_query_id' => $savedQuery->id,
            'title' => $validated['title'] ?? null,
            'visualization_type' => $vizType,
            'position' => $position,
            'width' => $validated['width'] ?? 1,
            'height' => $validated['height'] ?? 1,
        ]);

        $widget->load(['savedQuery.databaseConnection:id,name,driver,status']);

        return response()->json([
            'success' => true,
            'data' => $widget,
        ], 201);
    }

    /**
     * Update an existing dashboard widget.
     */
    public function updateWidget(Request $request, int $id, int $widgetId): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isEditableBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $widget = DashboardWidget::where('dashboard_id', $dashboard->id)->find($widgetId);

        if (!$widget) {
            return response()->json([
                'message' => 'Widget not found or unauthorized.'
            ], 404);
        }

        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'visualization_type' => 'nullable|string|in:table,bar,line,metric,none',
            'position' => 'nullable|integer|min:0',
            'width' => 'nullable|integer|min:1|max:4',
            'height' => 'nullable|integer|min:1|max:4',
        ]);

        $widget->update($validated);
        $widget->load(['savedQuery.databaseConnection:id,name,driver,status']);

        return response()->json([
            'success' => true,
            'data' => $widget,
        ]);
    }

    /**
     * Delete a widget from the dashboard.
     */
    public function deleteWidget(Request $request, int $id, int $widgetId): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isEditableBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $widget = DashboardWidget::where('dashboard_id', $dashboard->id)->find($widgetId);

        if (!$widget) {
            return response()->json([
                'message' => 'Widget not found or unauthorized.'
            ], 404);
        }

        $widget->delete();

        return response()->json([
            'success' => true,
            'message' => 'Widget removed from dashboard successfully.',
        ]);
    }

    /**
     * Execute all widgets on a dashboard through the zero-bypass security pipeline.
     * Supports global date filters, short-lived caching, and partial failure resilience.
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)
            ->with(['widgets.savedQuery.databaseConnection:id,name,driver,status'])
            ->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        // Permission check: Viewers can only execute company-shared dashboards
        if ($user->isViewer()) {
            if ($dashboard->visibility !== 'company') {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
            }
        } elseif (!$dashboard->isAccessibleBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $filterParams = [
            'date_preset' => $request->input('date_preset'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];
        $bypassCache = $request->boolean('bypass_cache', false);

        $widgetResults = [];

        foreach ($dashboard->widgets as $widget) {
            $savedQuery = $widget->savedQuery;

            if (!$savedQuery) {
                $widgetResults[] = [
                    'widget_id' => $widget->id,
                    'saved_query_id' => null,
                    'title' => $widget->title ?: 'Unknown Query',
                    'visualization_type' => $widget->visualization_type,
                    'position' => $widget->position,
                    'width' => $widget->width,
                    'height' => $widget->height,
                    'target_database_name' => null,
                    'success' => false,
                    'error' => 'Underlying saved query is missing or was deleted.',
                    'time_ms' => 0,
                    'results' => [],
                    'columns' => [],
                    'question' => null,
                    'sql' => null,
                    'filter_applied' => false,
                    'filter_status' => 'unsupported',
                    'filter_message' => 'Saved query not found.',
                    'cache_hit' => false,
                    'interpretation' => null,
                ];
                continue;
            }

            // Tenant boundary and accessibility defense-in-depth:
            if ($savedQuery->company_id !== $companyId || !$savedQuery->isAccessibleBy($user)) {
                $widgetResults[] = [
                    'widget_id' => $widget->id,
                    'saved_query_id' => $savedQuery->id,
                    'title' => $widget->title ?: $savedQuery->name,
                    'visualization_type' => $widget->visualization_type,
                    'position' => $widget->position,
                    'width' => $widget->width,
                    'height' => $widget->height,
                    'target_database_name' => null,
                    'success' => false,
                    'error' => 'Unauthorized access to saved query.',
                    'time_ms' => 0,
                    'results' => [],
                    'columns' => [],
                    'question' => null,
                    'sql' => null,
                    'filter_applied' => false,
                    'filter_status' => 'unsupported',
                    'filter_message' => 'Unauthorized access to saved query.',
                    'cache_hit' => false,
                    'interpretation' => null,
                ];
                continue;
            }

            // Execute through the shared zero-bypass pipeline with date filter & cache support
            $execResult = $this->executionService->execute(
                $savedQuery,
                $user->id,
                'dashboard',
                null,
                $filterParams,
                $bypassCache
            );

            $executionData = $execResult['execution'] ?? [];
            $isSuccess = ($execResult['success'] ?? false) && ($executionData['success'] ?? false);
            $results = $executionData['results'] ?? [];
            $errorMessage = $executionData['error']
                ?? $execResult['error']
                ?? ($isSuccess ? null : 'Query execution failed');

            $widgetResults[] = [
                'widget_id' => $widget->id,
                'saved_query_id' => $savedQuery->id,
                'title' => $widget->title ?: $savedQuery->name,
                'visualization_type' => $widget->visualization_type ?: $savedQuery->result_visualization_type,
                'position' => $widget->position,
                'width' => $widget->width,
                'height' => $widget->height,
                'target_database_name' => $savedQuery->target_database_name,
                'success' => $isSuccess,
                'error' => $errorMessage,
                'time_ms' => $executionData['time_ms'] ?? 0,
                'results' => $results,
                'columns' => !empty($results) ? array_keys($results[0]) : [],
                'guardrails' => $execResult['guardrails'] ?? null,
                'schema_validation' => $execResult['schema_validation'] ?? null,
                'question' => $savedQuery->natural_language_question,
                'sql' => $execResult['sql'] ?? $savedQuery->sql,
                'filter_applied' => $execResult['filter_applied'] ?? false,
                'filter_status' => $execResult['filter_status'] ?? 'none',
                'filter_message' => $execResult['filter_message'] ?? null,
                'cache_hit' => $execResult['cache_hit'] ?? false,
                'interpretation' => $execResult['interpretation'] ?? null,
            ];
        }

        return response()->json([
            'success' => true,
            'dashboard_id' => $dashboard->id,
            'dashboard_name' => $dashboard->name,
            'active_filters' => $filterParams,
            'widgets' => $widgetResults,
        ]);
    }

    /**
     * Export dashboard data as RFC 4180 CSV with formula injection defense and tenant isolation.
     */
    public function exportCsv(Request $request, int $id)
    {
        $companyId = $request->user()->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)
            ->with(['company', 'widgets.savedQuery.databaseConnection:id,name,driver,status'])
            ->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isAccessibleBy($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $filterParams = [
            'date_preset' => $request->input('date_preset'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        // Execute all widgets respecting the active filters
        $widgetResults = [];
        foreach ($dashboard->widgets as $widget) {
            $savedQuery = $widget->savedQuery;
            if ($savedQuery && $savedQuery->company_id === $companyId) {
                $widgetResults[] = [
                    'widget' => $widget,
                    'result' => $this->executionService->execute($savedQuery, $request->user()->id, 'dashboard', null, $filterParams, false)
                ];
            }
        }

        $filename = \Illuminate\Support\Str::slug($dashboard->name) . '-export-' . date('Y-m-d') . '.csv';

        $callback = function () use ($dashboard, $filterParams, $widgetResults) {
            $file = fopen('php://output', 'w');

            // UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Dashboard Metadata Header
            fputcsv($file, ['Dashboard Export']);
            fputcsv($file, ['Dashboard Name', $dashboard->name]);
            if ($dashboard->description) {
                fputcsv($file, ['Description', $dashboard->description]);
            }
            fputcsv($file, ['Company', $dashboard->company?->name ?? 'Default Organization']);
            $filterLabel = !empty($filterParams['date_preset']) ? "Preset: {$filterParams['date_preset']}" : 'All Time';
            if (!empty($filterParams['date_from']) && !empty($filterParams['date_to'])) {
                $filterLabel = "{$filterParams['date_from']} to {$filterParams['date_to']}";
            }
            fputcsv($file, ['Date Filter', $filterLabel]);
            fputcsv($file, ['Exported At', date('Y-m-d H:i:s T')]);
            fputcsv($file, []); // blank line separator

            // Helper to sanitize formula injection
            $sanitize = function ($value) {
                if (is_null($value)) return '';
                $valStr = is_array($value) || is_object($value) ? json_encode($value) : (string) $value;
                if (preg_match('/^[=+\-@\t\r]/', $valStr)) {
                    return "'" . $valStr;
                }
                return $valStr;
            };

            // Loop through each widget
            foreach ($widgetResults as $item) {
                $widget = $item['widget'];
                $res = $item['result'];
                $execData = $res['execution'] ?? [];
                $rows = $execData['results'] ?? [];

                fputcsv($file, ['------------------------------------------------------------']);
                fputcsv($file, ['Widget Title', $widget->title ?: $widget->savedQuery?->name ?: 'Untitled Widget']);
                fputcsv($file, ['Visualization Type', $widget->visualization_type ?: 'table']);
                fputcsv($file, ['Filter Status', $res['filter_message'] ?? 'None']);
                fputcsv($file, ['Execution Status', ($execData['success'] ?? false) ? 'Success' : 'Error']);

                if (!empty($rows)) {
                    $headers = array_keys((array) $rows[0]);
                    fputcsv($file, array_map($sanitize, $headers));

                    foreach ($rows as $row) {
                        $rowArr = (array) $row;
                        $line = [];
                        foreach ($headers as $h) {
                            $line[] = $sanitize($rowArr[$h] ?? '');
                        }
                        fputcsv($file, $line);
                    }
                } else {
                    fputcsv($file, ['No rows returned or query error']);
                }

                fputcsv($file, []); // blank line
            }

            fclose($file);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma' => 'no-cache',
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Expires' => '0',
        ]);
    }

    /**
     * Get executive summary JSON for printable/PDF dashboard representations.
     */
    public function exportSummary(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $dashboard = Dashboard::where('company_id', $companyId)
            ->with(['company', 'widgets.savedQuery.databaseConnection:id,name,driver,status'])
            ->find($id);

        if (!$dashboard) {
            return response()->json([
                'message' => 'Dashboard not found or unauthorized.'
            ], 404);
        }

        if (!$dashboard->isAccessibleBy($request->user())) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $filterParams = [
            'date_preset' => $request->input('date_preset'),
            'date_from' => $request->input('date_from'),
            'date_to' => $request->input('date_to'),
        ];

        // Execute widgets with active filters
        $widgetSummaries = [];
        foreach ($dashboard->widgets as $widget) {
            $savedQuery = $widget->savedQuery;
            if ($savedQuery && $savedQuery->company_id === $companyId) {
                $res = $this->executionService->execute($savedQuery, $request->user()->id, 'dashboard', null, $filterParams, false);
                $exec = $res['execution'] ?? [];
                $rows = $exec['results'] ?? [];

                // Extract summary value if metric or table
                $kpiValue = null;
                if (!empty($rows) && count($rows) === 1 && count((array)$rows[0]) === 1) {
                    $kpiValue = array_values((array)$rows[0])[0];
                }

                $widgetSummaries[] = [
                    'widget_id' => $widget->id,
                    'title' => $widget->title ?: $savedQuery->name,
                    'visualization_type' => $widget->visualization_type ?: 'table',
                    'success' => $exec['success'] ?? false,
                    'filter_applied' => $res['filter_applied'] ?? false,
                    'filter_message' => $res['filter_message'] ?? null,
                    'kpi_value' => $kpiValue,
                    'row_count' => count($rows),
                    'results_preview' => array_slice($rows, 0, 10),
                    'columns' => !empty($rows) ? array_keys((array)$rows[0]) : [],
                    'grain' => $res['interpretation']['grain'] ?? null,
                    'multiplication_risk' => $res['interpretation']['multiplication_risk'] ?? null,
                    'calculation_risk' => ($res['interpretation']['multiplication_risk']['detected'] ?? false),
                ];
            }
        }

        $totalWidgets = count($widgetSummaries);
        $successfulWidgets = count(array_filter($widgetSummaries, fn($w) => $w['success']));
        $failedWidgets = $totalWidgets - $successfulWidgets;

        return response()->json([
            'success' => true,
            'dashboard' => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'description' => $dashboard->description,
                'company' => $dashboard->company?->name,
                'exported_at' => date('Y-m-d H:i:s T'),
                'active_filters' => $filterParams,
            ],
            'summary_stats' => [
                'total_widgets' => $totalWidgets,
                'successful_widgets' => $successfulWidgets,
                'failed_widgets' => $failedWidgets,
            ],
            'widgets' => $widgetSummaries,
        ]);
    }
}
