<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Services\DatabaseSchemaService;
use Illuminate\Http\JsonResponse;

class SchemaController extends Controller
{
    protected DatabaseSchemaService $schemaService;

    public function __construct(DatabaseSchemaService $schemaService)
    {
        $this->schemaService = $schemaService;
    }

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->schemaService->getSchemaDetails()
        ]);
    }
}
