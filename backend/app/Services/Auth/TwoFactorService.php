<?php

namespace App\Services\Auth;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** TOTP two-factor authentication (RFC 6238) — works with Google Authenticator, Authy, 1Password, etc. */
class TwoFactorService
{
    public function __construct(private readonly Google2FA $google2fa = new Google2FA()) {}

    public function beginEnrollment(User $user): array
    {
        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => null])->save();
        $otpauth = $this->google2fa->getQRCodeUrl('StayVoyage', $user->email, $secret);
        $svg = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd())))->writeString($otpauth);

        return ['secret' => $secret, 'qr_svg' => $svg, 'otpauth_url' => $otpauth];
    }

    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || ! $this->verifyTotp($user, $code)) {
            return null;
        }
        $codes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => array_map(fn ($c) => hash('sha256', $c), $codes),
        ])->save();

        return $codes; // shown once; only hashes are stored
    }

    public function disable(User $user): void
    {
        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
    }

    /** Accepts a 6-digit TOTP code or a single-use recovery code. */
    public function verify(User $user, string $code): bool
    {
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code)) {
            return $this->verifyTotp($user, $code);
        }
        $hash = hash('sha256', Str::upper($code));
        $codes = $user->two_factor_recovery_codes ?? [];
        if (in_array($hash, $codes, true)) {
            $user->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$hash]))])->save();

            return true;
        }

        return false;
    }

    private function verifyTotp(User $user, string $code): bool
    {
        return (bool) $this->google2fa->verifyKey((string) $user->two_factor_secret, $code, 1);
    }
}
