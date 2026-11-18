<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $self = $request->user()?->id === $this->id;
        $roles = $this->whenLoaded('roles', fn () => $this->roles->pluck('slug')->values());

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_url,
            'status' => $this->status,
            'email_verified' => $this->email_verified_at !== null,
            'roles' => $roles,
            'is_staff' => $this->when($self || $request->user()?->isStaff(), fn () => $this->isStaff()),
            'is_partner' => $this->when($self || $request->user()?->isStaff(), fn () => $this->isPartner()),
            'permissions' => $this->when($self && $this->isStaff(), fn () => $this->isSuperAdmin() ? ['*'] : $this->permissionSlugs()),
            'two_factor_enabled' => $this->when($self || $request->user()?->isStaff(), fn () => $this->hasTwoFactorEnabled()),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
