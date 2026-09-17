<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DatabaseConnection;
use App\Models\SavedQuery;
use App\Models\SemanticMetric;
use App\Models\SemanticTableClassification;
use App\Models\SemanticTerm;
use App\Services\DatabaseConnectionManager;
use App\Services\DatabaseSchemaService;
use App\Services\SchemaIntrospectionService;
use App\Services\SemanticContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SemanticController extends Controller
{
    protected SemanticContextService $semanticService;
    protected SchemaIntrospectionService $introspectionService;
    protected DatabaseConnectionManager $connectionManager;
    protected DatabaseSchemaService $defaultSchemaService;

    public function __construct(
        SemanticContextService $semanticService,
        SchemaIntrospectionService $introspectionService,
        DatabaseConnectionManager $connectionManager,
        DatabaseSchemaService $defaultSchemaService
    ) {
        $this->semanticService = $semanticService;
        $this->introspectionService = $introspectionService;
        $this->connectionManager = $connectionManager;
        $this->defaultSchemaService = $defaultSchemaService;
    }

    // =========================================================================
    // 1. BUSINESS METRICS
    // =========================================================================

    /**
     * List all semantic metrics for the authenticated user's company.
     */
    public function indexMetrics(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id) {
            return response()->json(['success' => false, 'message' => 'Unauthorized.'], 401);
        }

        $query = SemanticMetric::where('company_id', $user->company_id)
            ->with(['creator:id,name,email', 'updater:id,name,email'])
            ->withCount(['terms', 'savedQueries'])
            ->orderBy('is_source_of_truth', 'desc')
            ->orderBy('name', 'asc');

        if ($request->boolean('active_only')) {
            $query->active();
        }

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%")
                  ->orWhere('source_table', 'like', "%{$search}%")
                  ->orWhere('source_column', 'like', "%{$search}%");
            });
        }

        return response()->json([
            'success' => true,
            'data' => $query->get(),
        ]);
    }

    /**
     * Store a new semantic metric (Admin only).
     */
    public function storeMetric(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators can create business metric definitions.',
            ], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-_]+$/i',
                Rule::unique('semantic_metrics', 'slug')->where('company_id', $user->company_id),
            ],
            'description' => 'nullable|string|max:1000',
            'definition' => 'required|string|max:2000',
            'source_table' => 'required|string|max:255|regex:/^[a-zA-Z0-9_]+$/',
            'source_column' => 'required|string|max:255|regex:/^[a-zA-Z0-9_]+$/',
            'aggregation' => ['required', Rule::in(['SUM', 'COUNT', 'AVG', 'MIN', 'MAX', 'COUNT_DISTINCT'])],
            'filter_condition' => 'nullable|string|max:500',
            'date_column' => 'nullable|string|max:255|regex:/^[a-zA-Z0-9_]+$/',
            'is_active' => 'boolean',
            'is_source_of_truth' => 'boolean',
        ]);

        $slug = !empty($validated['slug'])
            ? Str::slug($validated['slug'], '_')
            : Str::slug($validated['name'], '_');

        // Ensure slug uniqueness within company
        $baseSlug = $slug;
        $counter = 1;
        while (SemanticMetric::where('company_id', $user->company_id)->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}_{$counter}";
            $counter++;
        }

        $metric = SemanticMetric::create([
            'company_id' => $user->company_id,
            'name' => $validated['name'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'definition' => $validated['definition'],
            'source_table' => strtolower($validated['source_table']),
            'source_column' => strtolower($validated['source_column']),
            'aggregation' => strtoupper($validated['aggregation']),
            'filter_condition' => $validated['filter_condition'] ?? null,
            'date_column' => !empty($validated['date_column']) ? strtolower($validated['date_column']) : null,
            'is_active' => $validated['is_active'] ?? true,
            'is_source_of_truth' => $validated['is_source_of_truth'] ?? true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        AuditLog::record(
            $user->company_id,
            $user->id,
            'semantic_metric.create',
            'semantic_metric',
            $metric->id,
            ['name' => $metric->name, 'source_table' => $metric->source_table, 'aggregation' => $metric->aggregation]
        );

        return response()->json([
            'success' => true,
            'data' => $metric->load(['creator:id,name,email']),
            'message' => "Business metric '{$metric->name}' defined successfully.",
        ], 201);
    }

    /**
     * Show a specific semantic metric.
     */
    public function showMetric(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $metric = SemanticMetric::where('company_id', $user->company_id)
            ->with(['creator:id,name,email', 'updater:id,name,email', 'terms'])
            ->withCount('savedQueries')
            ->find($id);

        if (!$metric) {
            return response()->json(['success' => false, 'message' => 'Metric not found.'], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $metric,
        ]);
    }

    /**
     * Update a semantic metric (Admin only).
     */
    public function updateMetric(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators can update business metric definitions.',
            ], 403);
        }

        $metric = SemanticMetric::where('company_id', $user->company_id)->find($id);
        if (!$metric) {
            return response()->json(['success' => false, 'message' => 'Metric not found.'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'slug' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^[a-z0-9\-_]+$/i',
                Rule::unique('semantic_metrics', 'slug')
                    ->where('company_id', $user->company_id)
                    ->ignore($metric->id),
            ],
            'description' => 'nullable|string|max:1000',
            'definition' => 'sometimes|required|string|max:2000',
            'source_table' => 'sometimes|required|string|max:255|regex:/^[a-zA-Z0-9_]+$/',
            'source_column' => 'sometimes|required|string|max:255|regex:/^[a-zA-Z0-9_]+$/',
            'aggregation' => ['sometimes', 'required', Rule::in(['SUM', 'COUNT', 'AVG', 'MIN', 'MAX', 'COUNT_DISTINCT'])],
            'filter_condition' => 'nullable|string|max:500',
            'date_column' => 'nullable|string|max:255|regex:/^[a-zA-Z0-9_]+$/',
            'is_active' => 'boolean',
            'is_source_of_truth' => 'boolean',
        ]);

        if (isset($validated['source_table'])) {
            $validated['source_table'] = strtolower($validated['source_table']);
        }
        if (isset($validated['source_column'])) {
            $validated['source_column'] = strtolower($validated['source_column']);
        }
        if (isset($validated['date_column'])) {
            $validated['date_column'] = !empty($validated['date_column']) ? strtolower($validated['date_column']) : null;
        }
        if (isset($validated['aggregation'])) {
            $validated['aggregation'] = strtoupper($validated['aggregation']);
        }

        $validated['updated_by'] = $user->id;

        $metric->update($validated);

        AuditLog::record(
            $user->company_id,
            $user->id,
            'semantic_metric.update',
            'semantic_metric',
            $metric->id,
            ['updated_fields' => array_keys($validated)]
        );

        return response()->json([
            'success' => true,
            'data' => $metric->fresh(['creator:id,name,email', 'updater:id,name,email']),
            'message' => "Business metric '{$metric->name}' updated successfully.",
        ]);
    }

    /**
     * Delete a semantic metric (Admin only).
     */
    public function destroyMetric(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'Only administrators can delete business metric definitions.',
            ], 403);
        }

        $metric = SemanticMetric::where('company_id', $user->company_id)->find($id);
        if (!$metric) {
            return response()->json(['success' => false, 'message' => 'Metric not found.'], 404);
        }

        $metricName = $metric->name;
        $metric->delete();

        AuditLog::record(
            $user->company_id,
            $user->id,
            'semantic_metric.delete',
            'semantic_metric',
            $id,
            ['deleted_metric' => $metricName]
        );

        return response()->json([
            'success' => true,
            'message' => "Business metric '{$metricName}' deleted successfully.",
        ]);
    }

    // =========================================================================
    // 2. BUSINESS TERMINOLOGY MAPPINGS
    // =========================================================================

    /**
     * List company semantic terms.
     */
    public function indexTerms(Request $request): JsonResponse
    {
        $user = $request->user();
        $terms = SemanticTerm::where('company_id', $user->company_id)
            ->with(['metric:id,name,slug,source_table,source_column,aggregation'])
            ->orderBy('term', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $terms,
        ]);
    }

    /**
     * Store a semantic term (Admin only).
     */
    public function storeTerm(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin only.'], 403);
        }

        $validated = $request->validate([
            'term' => [
                'required',
                'string',
                'max:255',
                Rule::unique('semantic_terms', 'term')->where('company_id', $user->company_id),
            ],
            'metric_id' => 'nullable|exists:semantic_metrics,id',
            'target_type' => ['required', Rule::in(['metric', 'table', 'column', 'filter', 'concept'])],
            'target_name' => 'required|string|max:255',
            'definition' => 'nullable|string|max:1000',
        ]);

        $term = SemanticTerm::create([
            'company_id' => $user->company_id,
            'term' => strtolower(trim($validated['term'])),
            'metric_id' => $validated['metric_id'] ?? null,
            'target_type' => $validated['target_type'],
            'target_name' => trim($validated['target_name']),
            'definition' => $validated['definition'] ?? null,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        AuditLog::record(
            $user->company_id,
            $user->id,
            'semantic_term.create',
            'semantic_term',
            $term->id,
            ['term' => $term->term, 'target_type' => $term->target_type]
        );

        return response()->json([
            'success' => true,
            'data' => $term->load('metric'),
            'message' => "Terminology mapping for '{$term->term}' created successfully.",
        ], 201);
    }

    /**
     * Update a semantic term (Admin only).
     */
    public function updateTerm(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin only.'], 403);
        }

        $term = SemanticTerm::where('company_id', $user->company_id)->find($id);
        if (!$term) {
            return response()->json(['success' => false, 'message' => 'Term not found.'], 404);
        }

        $validated = $request->validate([
            'term' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('semantic_terms', 'term')
                    ->where('company_id', $user->company_id)
                    ->ignore($term->id),
            ],
            'metric_id' => 'nullable|exists:semantic_metrics,id',
            'target_type' => ['sometimes', 'required', Rule::in(['metric', 'table', 'column', 'filter', 'concept'])],
            'target_name' => 'sometimes|required|string|max:255',
            'definition' => 'nullable|string|max:1000',
        ]);

        if (isset($validated['term'])) {
            $validated['term'] = strtolower(trim($validated['term']));
        }
        $validated['updated_by'] = $user->id;

        $term->update($validated);

        return response()->json([
            'success' => true,
            'data' => $term->fresh('metric'),
            'message' => "Terminology mapping updated successfully.",
        ]);
    }

    /**
     * Delete a semantic term (Admin only).
     */
    public function destroyTerm(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin only.'], 403);
        }

        $term = SemanticTerm::where('company_id', $user->company_id)->find($id);
        if (!$term) {
            return response()->json(['success' => false, 'message' => 'Term not found.'], 404);
        }

        $termName = $term->term;
        $term->delete();

        return response()->json([
            'success' => true,
            'message' => "Terminology mapping '{$termName}' deleted.",
        ]);
    }

    // =========================================================================
    // 3. TABLE CLASSIFICATIONS & SOURCE-OF-TRUTH
    // =========================================================================

    /**
     * List all table classifications for the company.
     */
    public function indexClassifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $classifications = SemanticTableClassification::where('company_id', $user->company_id)
            ->orderBy('classification', 'asc')
            ->orderBy('table_name', 'asc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $classifications,
        ]);
    }

    /**
     * Upsert table classification (Admin only).
     */
    public function storeClassification(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin only.'], 403);
        }

        $validated = $request->validate([
            'table_name' => 'required|string|max:255|regex:/^[a-zA-Z0-9_]+$/',
            'classification' => ['required', Rule::in(['business', 'staging', 'archive', 'test', 'internal', 'unknown'])],
            'description' => 'nullable|string|max:1000',
            'is_preferred_source' => 'boolean',
            'preferred_for_concept' => 'nullable|string|max:255',
        ]);

        $tableName = strtolower(trim($validated['table_name']));

        $classification = SemanticTableClassification::updateOrCreate(
            [
                'company_id' => $user->company_id,
                'table_name' => $tableName,
            ],
            [
                'classification' => strtolower($validated['classification']),
                'description' => $validated['description'] ?? null,
                'is_preferred_source' => $validated['is_preferred_source'] ?? false,
                'preferred_for_concept' => $validated['preferred_for_concept'] ?? null,
                'updated_by' => $user->id,
                'created_by' => $user->id,
            ]
        );

        AuditLog::record(
            $user->company_id,
            $user->id,
            'semantic_classification.save',
            'semantic_table_classification',
            $classification->id,
            ['table_name' => $tableName, 'classification' => $classification->classification]
        );

        return response()->json([
            'success' => true,
            'data' => $classification,
            'message' => "Classification for table '{$tableName}' saved as [{$classification->classification}].",
        ]);
    }

    /**
     * Delete table classification (Admin only).
     */
    public function destroyClassification(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->company_id || !$user->isAdmin()) {
            return response()->json(['success' => false, 'message' => 'Admin only.'], 403);
        }

        $classification = SemanticTableClassification::where('company_id', $user->company_id)->find($id);
        if (!$classification) {
            return response()->json(['success' => false, 'message' => 'Classification not found.'], 404);
        }

        $tableName = $classification->table_name;
        $classification->delete();

        return response()->json([
            'success' => true,
            'message' => "Classification for table '{$tableName}' removed.",
        ]);
    }

    // =========================================================================
    // 4. DATA LINEAGE
    // =========================================================================

    /**
     * Get data lineage graph for metric(s).
     */
    public function lineage(Request $request): JsonResponse
    {
        $user = $request->user();
        $metricId = $request->query('metric_id') ? (int)$request->query('metric_id') : null;
        $connId = $request->query('database_connection_id') ? (int)$request->query('database_connection_id') : null;

        $schema = [];

        if ($connId) {
            $conn = DatabaseConnection::where('company_id', $user->company_id)->find($connId);
            if ($conn) {
                try {
                    $schema = $this->introspectionService->introspect($conn);
                } catch (\Throwable $e) {
                    $schema = $this->defaultSchemaService->getSchemaDetails();
                }
            }
        } else {
            $schema = $this->defaultSchemaService->getSchemaDetails();
        }

        $lineageGraph = $this->semanticService->buildLineageGraph($user->company_id, $metricId, $schema);

        return response()->json([
            'success' => true,
            'data' => $lineageGraph,
        ]);
    }

    // =========================================================================
    // 5. SEMANTIC DRIFT DETECTION
    // =========================================================================

    /**
     * Detect semantic drift on a saved query.
     */
    public function detectDrift(Request $request, int $savedQueryId): JsonResponse
    {
        $user = $request->user();
        $savedQuery = SavedQuery::visibleTo($user)->find($savedQueryId);

        if (!$savedQuery) {
            return response()->json(['success' => false, 'message' => 'Saved query not found.'], 404);
        }

        $driftResult = $this->semanticService->detectSemanticDrift($savedQuery);

        return response()->json([
            'success' => true,
            'data' => $driftResult,
        ]);
    }
}
