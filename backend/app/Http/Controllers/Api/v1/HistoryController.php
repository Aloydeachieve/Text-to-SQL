<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\QueryLog;
use Illuminate\Http\JsonResponse;

class HistoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $history = QueryLog::orderBy('created_at', 'desc')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }
}
