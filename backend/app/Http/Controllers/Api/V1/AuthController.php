<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\Role;
use App\Models\User;
use App\Notifications\WelcomeNotification;
use App\Services\Auth\TwoFactorService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::query()->create($request->safe()->only(['name', 'email', 'phone', 'password']));
            $user->roles()->attach(Role::query()->where('slug', Role::CUSTOMER)->value('id'));

            return $user;
        });

        event(new Registered($user)); // sends verification email
        $user->notify(new WelcomeNotification());

        Auth::guard('web')->login($user);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        Log::channel('auth')->info('User registered', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return $this->created(new UserResource($user->load('roles')), 'Welcome to StayVoyage! Please check your inbox to verify your email.');
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only('email', 'password');
        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            Log::channel('auth')->warning('Failed login', ['email' => $credentials['email'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        if ($user->isSuspended()) {
            Log::channel('auth')->warning('Suspended user login attempt', ['user_id' => $user->id, 'ip' => $request->ip()]);

            return $this->fail('Your account has been suspended. Please contact support.', 403, [], 'account_suspended');
        }

        // Second factor required: park the user id in the session until the TOTP code is verified.
        if ($user->hasTwoFactorEnabled()) {
            if (! $request->hasSession()) {
                return $this->fail("Two-factor login requires the StayVoyage web app.", 400, [], "session_required");
            }
            $request->session()->put('login.2fa', ['id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->timestamp]);

            return $this->ok(['two_factor' => true], 'Enter the 6-digit code from your authenticator app.');
        }

        return $this->completeLogin($request, $user, $request->boolean('remember'));
    }

    public function twoFactorChallenge(Request $request): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $pending = $request->session()->get('login.2fa');
        if (! $pending || now()->timestamp - $pending['at'] > 300) {
            $request->session()->forget('login.2fa');

            return $this->fail('Your login session expired. Please sign in again.', 419, [], 'two_factor_expired');
        }
        $user = User::query()->find($pending['id']);
        if (! $user || ! $this->twoFactor->verify($user, (string) $request->input('code'))) {
            Log::channel('auth')->warning('Failed 2FA challenge', ['user_id' => $pending['id'], 'ip' => $request->ip()]);
            throw ValidationException::withMessages(['code' => 'That code is invalid. Please try again.']);
        }
        $request->session()->forget('login.2fa');

        return $this->completeLogin($request, $user, (bool) $pending['remember']);
    }

    private function completeLogin(Request $request, User $user, bool $remember): JsonResponse
    {
        Auth::guard('web')->login($user, $remember);
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->saveQuietly();
        Log::channel('auth')->info('Login', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return $this->ok(new UserResource($user->load('roles')), 'Welcome back, '.strtok($user->name, ' ').'!');
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $this->ok(null, 'You have been logged out.');
    }

    /** Current session user, or null for guests (200 either way — avoids noisy 401s on public pages). */
    public function session(Request $request): JsonResponse
    {
        $user = auth('sanctum')->user();

        return $this->ok($user && ! $user->isSuspended() ? new UserResource($user->load('roles')) : null);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok(new UserResource($request->user()->load('roles')));
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower((string) $request->input('email'))]);

        // Same response whether or not the account exists (prevents account enumeration).
        return $this->ok(null, 'If an account exists for that email, a password reset link is on its way.');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
            Log::channel('auth')->info('Password reset', ['user_id' => $user->id]);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return $this->fail('This reset link is invalid or has expired. Please request a new one.', 422, [], 'invalid_reset_token');
        }

        return $this->ok(null, 'Your password has been reset. You can now log in.');
    }

    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        if (! URL::hasValidSignature($request, false)) {
            return $this->fail('This verification link is invalid or has expired.', 403, [], 'invalid_signature');
        }
        $user = User::query()->findOrFail($id);
        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return $this->fail('This verification link is invalid.', 403, [], 'invalid_signature');
        }
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return $this->ok(null, 'Your email is verified. Thank you!');
    }

    public function resendVerification(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user->hasVerifiedEmail()) {
            return $this->ok(null, 'Your email is already verified.');
        }
        $user->sendEmailVerificationNotification();

        return $this->ok(null, 'A new verification link has been sent to '.$user->email.'.');
    }
}
