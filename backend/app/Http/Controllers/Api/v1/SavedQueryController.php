<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Models\QueryLog;
use App\Models\SavedQuery;
use App\Services\DatabaseConnectionManager;
use App\Services\DatabaseSchemaService;
use App\Services\SavedQueryExecutionService;
use App\Services\SchemaIntrospectionService;
use App\Services\SqlExecutorService;
use App\Services\SqlGuardrailService;
use App\Services\SqlSchemaValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedQueryController extends Controller
{
    protected SqlGuardrailService $guardrailService;
    protected SqlSchemaValidator $schemaValidator;
    protected SqlExecutorService $executorService;
    protected DatabaseConnectionManager $connectionManager;
    protected SchemaIntrospectionService $introspectionService;
    protected DatabaseSchemaService $defaultSchemaService;
    protected SavedQueryExecutionService $executionService;

    public function __construct(
        SqlGuardrailService $guardrailService,
        SqlSchemaValidator $schemaValidator,
        SqlExecutorService $executorService,
        DatabaseConnectionManager $connectionManager,
        SchemaIntrospectionService $introspectionService,
        DatabaseSchemaService $defaultSchemaService,
        SavedQueryExecutionService $executionService
    ) {
        $this->guardrailService = $guardrailService;
        $this->schemaValidator = $schemaValidator;
        $this->executorService = $executorService;
        $this->connectionManager = $connectionManager;
        $this->introspectionService = $introspectionService;
        $this->defaultSchemaService = $defaultSchemaService;
        $this->executionService = $executionService;
    }

    /**
     * List all saved queries for the authenticated user's company according to visibility.
     */
    public function index(Request $request): JsonResponse
    {
        $query = SavedQuery::visibleTo($request->user())
            ->with([
                'user:id,name,email',
                'databaseConnection:id,name,driver,status'
            ])
            ->withCount('dashboardWidgets')
            ->orderBy('created_at', 'desc');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('natural_language_question', 'like', "%{$search}%");
            });
        }

        if ($request->has('database_connection_id')) {
            $connFilter = $request->input('database_connection_id');
            if ($connFilter === 'null' || $connFilter === '' || $connFilter === null) {
                $query->whereNull('database_connection_id');
            } else {
                $query->where('database_connection_id', (int) $connFilter);
            }
        }

        $savedQueries = $query->get();

        return response()->json([
            'success' => true,
            'data' => $savedQueries,
        ]);
    }

    /**
     * Store a new saved query for the authenticated user's company (Admin and Analyst).
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
            'natural_language_question' => 'required|string|max:2000',
            'sql' => 'required|string|max:5000',
            'database_connection_id' => 'nullable|integer',
            'dialect' => 'nullable|string|in:mysql,pgsql',
            'result_visualization_type' => 'nullable|string|in:table,bar,line,none',
            'visibility' => 'nullable|string|in:private,company',
        ]);

        // Security check: Guardrail validation prevents saving unsafe DDL/DML
        $guardrailResult = $this->guardrailService->validate($validated['sql']);
        if (!$guardrailResult['allowed']) {
            return response()->json([
                'message' => 'Cannot save unsafe query: ' . $guardrailResult['reason'],
                'error' => $guardrailResult['reason'],
            ], 422);
        }

        $connId = $validated['database_connection_id'] ?? null;
        $targetDbName = null;
        $isDemo = false;
        $dialect = $validated['dialect'] ?? 'mysql';

        if ($connId !== null) {
            $dbConnection = DatabaseConnection::where('company_id', $companyId)
                ->where('id', $connId)
                ->first();

            if (!$dbConnection) {
                return response()->json([
                    'message' => 'Database connection not found or unauthorized.'
                ], 404);
            }

            $targetDbName = $dbConnection->name;
            $dialect = strtolower($dbConnection->driver);
        } else {
            $isDemo = true;
            $targetDbName = 'Demo Database (MySQL)';
        }

        $visibility = $validated['visibility'] ?? 'private';

        $savedQuery = SavedQuery::create([
            'company_id' => $companyId,
            'user_id' => $user->id,
            'database_connection_id' => $connId,
            'target_database_name' => $targetDbName,
            'is_demo' => $isDemo,
            'visibility' => $visibility,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'natural_language_question' => $validated['natural_language_question'],
            'sql' => $validated['sql'],
            'dialect' => $dialect,
            'result_visualization_type' => $validated['result_visualization_type'] ?? null,
        ]);

        if ($visibility === 'company') {
            \App\Models\AuditLog::record(
                $companyId,
                $user->id,
                'saved_query_shared',
                'saved_query',
                $savedQuery->id,
                ['name' => $savedQuery->name]
            );
        }

        $savedQuery->load(['user:id,name,email', 'databaseConnection:id,name,driver,status']);

        return response()->json([
            'success' => true,
            'data' => $savedQuery,
        ], 201);
    }

    /**
     * Show details of a single saved query.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $savedQuery = SavedQuery::where('company_id', $companyId)
            ->with([
                'user:id,name,email',
                'databaseConnection:id,name,driver,status'
            ])
            ->find($id);

        if (!$savedQuery) {
            return response()->json([
                'message' => 'Saved query not found or unauthorized.'
            ], 404);
        }

        if (!$savedQuery->isAccessibleBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $savedQuery,
        ]);
    }

    /**
     * Update an existing saved query.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $savedQuery = SavedQuery::where('company_id', $companyId)->find($id);

        if (!$savedQuery) {
            return response()->json([
                'message' => 'Saved query not found or unauthorized.'
            ], 404);
        }

        if (!$savedQuery->isEditableBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'sql' => 'sometimes|required|string|max:5000',
            'result_visualization_type' => 'nullable|string|in:table,bar,line,none',
            'visibility' => 'sometimes|required|string|in:private,company',
        ]);

        if (isset($validated['sql'])) {
            $guardrailResult = $this->guardrailService->validate($validated['sql']);
            if (!$guardrailResult['allowed']) {
                return response()->json([
                    'message' => 'Cannot update to unsafe query: ' . $guardrailResult['reason'],
                    'error' => $guardrailResult['reason'],
                ], 422);
            }
        }

        // Audit visibility change
        if (isset($validated['visibility']) && $validated['visibility'] !== $savedQuery->visibility) {
            $action = $validated['visibility'] === 'company' ? 'saved_query_shared' : 'saved_query_unshared';
            \App\Models\AuditLog::record(
                $companyId,
                $user->id,
                $action,
                'saved_query',
                $savedQuery->id,
                ['name' => $savedQuery->name, 'old_visibility' => $savedQuery->visibility, 'new_visibility' => $validated['visibility']]
            );
        }

        $savedQuery->update($validated);
        $savedQuery->load(['user:id,name,email', 'databaseConnection:id,name,driver,status']);

        return response()->json([
            'success' => true,
            'data' => $savedQuery,
        ]);
    }

    /**
     * Delete a saved query.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $savedQuery = SavedQuery::where('company_id', $companyId)->find($id);

        if (!$savedQuery) {
            return response()->json([
                'message' => 'Saved query not found or unauthorized.'
            ], 404);
        }

        if (!$savedQuery->isEditableBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $savedQuery->delete();

        return response()->json([
            'success' => true,
            'message' => 'Saved query deleted successfully.',
        ]);
    }

    /**
     * Re-execute a saved query through the full security and validation pipeline.
     */
    public function execute(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $companyId = $user->company_id;

        $savedQuery = SavedQuery::where('company_id', $companyId)->find($id);

        if (!$savedQuery) {
            return response()->json([
                'message' => 'Saved query not found or unauthorized.'
            ], 404);
        }

        // Check execution permission: Viewers can only execute company-shared queries
        if ($user->isViewer()) {
            if ($savedQuery->visibility !== 'company') {
                return response()->json([
                    'success' => false,
                    'message' => 'You do not have permission to perform this action.',
                ], 403);
            }
        } elseif (!$savedQuery->isAccessibleBy($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $result = $this->executionService->execute(
            $savedQuery,
            $user->id,
            'saved_query',
            $request->input('sql')
        );

        $statusCode = $result['status_code'] ?? 200;
        unset($result['status_code']);

        return response()->json($result, $statusCode);
    }
}
