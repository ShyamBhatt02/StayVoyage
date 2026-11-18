<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('role:super-admin,admin') */
class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401, [], 'unauthenticated');
        }
        if (! $user->hasRole(...$roles)) {
            Log::channel('auth')->warning('Role denied', [
                'user_id' => $user->id, 'roles' => $roles, 'path' => $request->path(), 'ip' => $request->ip(),
            ]);

            return ApiResponse::error('You do not have access to this area.', 403, [], 'forbidden');
        }

        return $next($request);
    }
}
