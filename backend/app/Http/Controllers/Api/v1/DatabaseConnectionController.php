<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\DatabaseConnection;
use App\Services\DatabaseConnectionManager;
use App\Services\SchemaIntrospectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

class DatabaseConnectionController extends Controller
{
    protected DatabaseConnectionManager $connectionManager;
    protected SchemaIntrospectionService $introspectionService;

    public function __construct(
        DatabaseConnectionManager $connectionManager,
        SchemaIntrospectionService $introspectionService
    ) {
        $this->connectionManager = $connectionManager;
        $this->introspectionService = $introspectionService;
    }

    /**
     * Test database connectivity without persisting (Admin only).
     */
    public function test(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $validated = $request->validate([
            'driver' => 'required|string|in:mysql,pgsql',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|between:1,65535',
            'database' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'present|nullable|string',
        ]);
        $validated['password'] = (string) ($validated['password'] ?? '');

        $result = $this->connectionManager->testConnection($validated);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * List all database connections for the authenticated user's company (Admin and Analyst).
     */
    public function index(Request $request): JsonResponse
    {
        if ($request->user()->isViewer()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $companyId = $request->user()->company_id;

        $connections = DatabaseConnection::where('company_id', $companyId)
            ->orderBy('created_at', 'desc')
            ->get()
            ->makeHidden(['password']);

        return response()->json([
            'success' => true,
            'data' => $connections,
        ]);
    }

    /**
     * Store and optionally test a new database connection for the company (Admin only).
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $companyId = $request->user()->company_id;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'driver' => 'required|string|in:mysql,pgsql',
            'host' => 'required|string|max:255',
            'port' => 'required|integer|between:1,65535',
            'database' => 'required|string|max:255',
            'username' => 'required|string|max:255',
            'password' => 'present|nullable|string',
        ]);
        $validated['password'] = (string) ($validated['password'] ?? '');

        // Validate connectivity before saving
        $testResult = $this->connectionManager->testConnection($validated);
        $status = $testResult['success'] ? 'connected' : 'failed';

        $connection = DatabaseConnection::create([
            'company_id' => $companyId,
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'host' => $validated['host'],
            'port' => $validated['port'],
            'database' => $validated['database'],
            'username' => $validated['username'],
            'password' => $validated['password'], // cast encrypts automatically
            'status' => $status,
            'last_tested_at' => Carbon::now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => $testResult['success']
                ? 'Database connection saved and verified successfully.'
                : 'Database connection saved, but test probe failed: ' . $testResult['message'],
            'data' => $connection->makeHidden(['password']),
        ], 201);
    }

    /**
     * Get single connection details (strictly tenant-scoped).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if ($request->user()->isViewer()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $companyId = $request->user()->company_id;

        $connection = DatabaseConnection::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection not found or unauthorized.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $connection->makeHidden(['password']),
        ]);
    }

    /**
     * Delete connection (Admin only; strictly tenant-scoped).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $companyId = $request->user()->company_id;

        $connection = DatabaseConnection::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection not found or unauthorized.',
            ], 404);
        }

        if (!$request->user()->isAdmin()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $this->connectionManager->purgeConnection($connection);
        $connection->delete();

        return response()->json([
            'success' => true,
            'message' => 'Database connection deleted successfully.',
        ]);
    }

    /**
     * Introspect schema for an authorized company connection.
     */
    public function schema(Request $request, int $id): JsonResponse
    {
        if ($request->user()->isViewer()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $companyId = $request->user()->company_id;

        $connection = DatabaseConnection::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection not found or unauthorized.',
            ], 404);
        }

        try {
            $schema = $this->introspectionService->introspect($connection);

            return response()->json([
                'success' => true,
                'data' => $schema,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Unable to introspect database schema: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Check health and latency of an authorized company connection.
     */
    public function health(Request $request, int $id): JsonResponse
    {
        if ($request->user()->isViewer()) {
            return response()->json([
                'success' => false,
                'message' => 'You do not have permission to perform this action.',
            ], 403);
        }

        $companyId = $request->user()->company_id;

        $connection = DatabaseConnection::where('company_id', $companyId)
            ->where('id', $id)
            ->first();

        if (!$connection) {
            return response()->json([
                'success' => false,
                'message' => 'Database connection not found or unauthorized.',
            ], 404);
        }

        $healthResult = $this->connectionManager->checkHealth($connection);

        return response()->json([
            'success' => true,
            'data' => array_merge([
                'id' => $connection->id,
                'name' => $connection->name,
                'driver' => $connection->driver,
            ], $healthResult),
        ]);
    }
}
