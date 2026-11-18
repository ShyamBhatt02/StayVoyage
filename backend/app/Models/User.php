<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use App\Support\Media;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name', 'email', 'phone', 'password', 'avatar_path', 'status', 'suspension_reason', 'preferences',
    ];

    protected $hidden = [
        'password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes',
    ];

    /** Per-request permission cache. */
    protected ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    // ---------------------------------------------------------------- relations

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    public function membershipTransactions(): HasMany
    {
        return $this->hasMany(MembershipTransaction::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(UserTrip::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function savedHotels(): BelongsToMany
    {
        return $this->belongsToMany(Hotel::class, 'saved_hotels')->withPivot('created_at');
    }

    // ---------------------------------------------------------------- roles & permissions

    public function hasRole(string ...$slugs): bool
    {
        $roles = $this->relationLoaded('roles') ? $this->roles : $this->roles()->get();

        return $roles->whereIn('slug', $slugs)->isNotEmpty();
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN);
    }

    /** Super admins and staff/admins can enter the admin panel. */
    public function isStaff(): bool
    {
        return $this->hasRole(Role::SUPER_ADMIN, Role::ADMIN);
    }

    public function isPartner(): bool
    {
        return $this->hasRole(Role::HOTEL_PARTNER);
    }

    public function permissionSlugs(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        return $this->permissionCache = Permission::query()
            ->whereHas('roles', fn ($q) => $q->whereIn('roles.id', $this->roles()->pluck('roles.id')))
            ->pluck('slug')
            ->all();
    }

    public function hasPermission(string $slug): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        return in_array($slug, $this->permissionSlugs(), true);
    }

    public function managesHotel(int $hotelId): bool
    {
        return $this->hotels()->whereKey($hotelId)->exists();
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    // ---------------------------------------------------------------- membership helpers

    /** The membership that can currently be used for bookings (active and not past expiry). */
    public function activeMembership(): ?Membership
    {
        return $this->memberships()
            ->where('status', MembershipStatus::Active->value)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now())
            ->latest('expires_at')
            ->first();
    }

    /** Null when no avatar is uploaded — the SPA renders brand-coloured initials (no third-party avatar service). */
    public function getAvatarUrlAttribute(): ?string
    {
        return Media::url($this->avatar_path);
    }

    // ---------------------------------------------------------------- notifications

    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification());
    }
}
