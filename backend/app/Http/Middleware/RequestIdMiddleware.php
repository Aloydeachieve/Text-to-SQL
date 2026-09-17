<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestIdMiddleware
{
    /**
     * Handle an incoming request and ensure a correlated Request ID is present.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $incomingId = $request->header('X-Request-ID');

        // Sanitize incoming Request ID: allow alphanumeric, hyphens, underscores up to 64 chars
        if (!empty($incomingId) && preg_match('/^[a-zA-Z0-9\-_]{4,64}$/', $incomingId)) {
            $requestId = $incomingId;
        } else {
            $requestId = 'req_' . Str::uuid()->toString();
        }

        // Store request_id in request attributes for controller/logger access
        $request->attributes->set('request_id', $requestId);

        // Bind request_id to Laravel's global structured log context
        Log::withContext([
            'request_id' => $requestId,
        ]);

        $response = $next($request);

        // Append X-Request-ID to the outgoing response headers
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
