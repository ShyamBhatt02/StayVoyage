<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When `security.enforce_admin_2fa` is on, staff must enrol in TOTP 2FA before using the admin API.
 * Staff who HAVE 2FA enabled are always challenged at login (see AuthController), regardless of this flag.
 */
class EnsureAdminTwoFactor
{
    public function __construct(private readonly SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user && $user->isStaff() && $this->settings->bool('security.enforce_admin_2fa') && ! $user->hasTwoFactorEnabled()) {
            return ApiResponse::error('Two-factor authentication must be enabled to access the admin panel.', 403, [], 'two_factor_setup_required');
        }

        return $next($request);
    }
}
