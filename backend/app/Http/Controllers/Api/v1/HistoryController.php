<?php

namespace App\Http\Controllers\Api\v1;

use App\Http\Controllers\Controller;
use App\Models\QueryLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistoryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = QueryLog::orderBy('created_at', 'desc');

        $user = $request->user('sanctum');
        if ($user && $user->company_id) {
            $query->where(function ($q) use ($user) {
                if ($user->isAdmin()) {
                    $q->where('company_id', $user->company_id);
                } else {
                    $q->where('user_id', $user->id);
                }
                $q->orWhereNull('company_id');
            });
        }

        $history = $query->limit(50)->get();

        return response()->json([
            'success' => true,
            'data' => $history
        ]);
    }
}
