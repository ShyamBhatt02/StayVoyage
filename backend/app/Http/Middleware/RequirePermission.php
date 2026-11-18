<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/** Usage: ->middleware('permission:hotels.manage') — any listed permission grants access. */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        if (! $user) {
            return ApiResponse::error('Unauthenticated.', 401, [], 'unauthenticated');
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        Log::channel('auth')->warning('Permission denied', [
            'user_id' => $user->id, 'permissions' => $permissions, 'path' => $request->path(), 'ip' => $request->ip(),
        ]);

        return ApiResponse::error('You do not have permission to perform this action.', 403, [], 'forbidden');
    }
}
