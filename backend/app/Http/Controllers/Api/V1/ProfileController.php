<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Services\AuditLogger;
use App\Services\Auth\TwoFactorService;
use App\Services\ImageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();
        $request->merge(['email' => strtolower(trim((string) $request->input('email', $user->email)))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80', 'regex:/^[\pL\s.\'-]+$/u'],
            'email' => ['required', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['required', 'string', 'regex:/^\+?\d{10,15}$/'],
        ]);
        $emailChanged = $data['email'] !== $user->email;
        $user->fill($data);
        if ($emailChanged) {
            $user->email_verified_at = null;
        }
        $user->save();
        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        return $this->ok(new UserResource($user->load('roles')), $emailChanged ? 'Profile updated. Please verify your new email address.' : 'Profile updated.');
    }

    public function avatar(Request $request, ImageUploadService $images): JsonResponse
    {
        $request->validate(['avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096', 'dimensions:min_width=100,min_height=100']]);
        $user = $request->user();
        $images->delete($user->avatar_path);
        $user->forceFill(['avatar_path' => $images->store($request->file('avatar'), 'avatars', 512)])->save();

        return $this->ok(new UserResource($user->load('roles')), 'Profile photo updated.');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', Password::defaults(), 'different:current_password'],
        ]);
        $user = $request->user();
        if (! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => 'Your current password is incorrect.']);
        }
        $user->forceFill(['password' => $request->input('password')])->save();
        // Sign out other devices.
        Auth::guard('web')->logoutOtherDevices($request->input('password'));
        Log::channel('auth')->info('Password changed', ['user_id' => $user->id, 'ip' => $request->ip()]);

        return $this->ok(null, 'Password changed. Other devices have been signed out.');
    }

    // --------------------------------------------------------------- two-factor

    public function twoFactorSetup(Request $request, TwoFactorService $tfa): JsonResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            return $this->fail('Two-factor authentication is already enabled.', 409, [], 'already_enabled');
        }

        return $this->ok($tfa->beginEnrollment($user), 'Scan the QR code with your authenticator app, then enter the 6-digit code.');
    }

    public function twoFactorConfirm(Request $request, TwoFactorService $tfa, AuditLogger $audit): JsonResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $codes = $tfa->confirm($request->user(), (string) $request->input('code'));
        if (! $codes) {
            throw ValidationException::withMessages(['code' => 'That code did not match. Check your device clock and try again.']);
        }
        $audit->log('security.2fa_enabled', $request->user());

        return $this->ok(['recovery_codes' => $codes], 'Two-factor authentication is on. Save your recovery codes somewhere safe.');
    }

    public function twoFactorDisable(Request $request, TwoFactorService $tfa, AuditLogger $audit): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);
        if (! Hash::check($request->input('password'), $request->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Password is incorrect.']);
        }
        $tfa->disable($request->user());
        $audit->log('security.2fa_disabled', $request->user());

        return $this->ok(null, 'Two-factor authentication has been turned off.');
    }
}
