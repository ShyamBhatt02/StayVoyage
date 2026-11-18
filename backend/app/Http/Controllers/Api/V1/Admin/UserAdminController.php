<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BookingResource;
use App\Http\Resources\MembershipResource;
use App\Http\Resources\PaymentResource;
use App\Http\Resources\TripResource;
use App\Http\Resources\UserResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;

class UserAdminController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'role' => ['nullable', 'string', 'max:30'], 'status' => ['nullable', 'in:active,suspended'],
            'member' => ['nullable', 'boolean']]);
        $users = User::query()->with('roles')
            ->withCount(['bookings as bookings_count' => fn ($q) => $q->whereIn('status', ['confirmed', 'completed'])])
            ->when($request->query('q'), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'like', "%{$t}%")->orWhere('email', 'like', "%{$t}%")->orWhere('phone', 'like', "%{$t}%")))
            ->when($request->query('role'), fn ($q, $r) => $q->whereHas('roles', fn ($w) => $w->where('slug', $r)))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->boolean('member'), fn ($q) => $q->whereHas('memberships', fn ($m) => $m->where('status', 'active')->where('expires_at', '>', now())))
            ->latest()->paginate(20);

        return $this->ok(UserResource::collection($users)->additional([]), 'OK');
    }

    public function show(User $user): JsonResponse
    {
        $user->load('roles');

        return $this->ok([
            'user' => new UserResource($user),
            'memberships' => MembershipResource::collection($user->memberships()->latest()->get()),
            'bookings' => BookingResource::collection($user->bookings()->with(['hotel.coverImage', 'trip'])->latest()->limit(20)->get()),
            'payments' => PaymentResource::collection($user->payments()->with('payable')->latest()->limit(20)->get()),
            'trips' => TripResource::collection($user->trips()->with('days.items')->latest()->limit(10)->get()),
            'hotels' => $user->isPartner() ? $user->hotels()->get(['hotels.id', 'hotels.name']) : [],
        ]);
    }

    public function suspend(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:200']]);
        if ($user->id === $request->user()->id || $user->isSuperAdmin()) {
            return $this->fail('This account cannot be suspended.', 403, [], 'forbidden');
        }
        $user->forceFill(['status' => User::STATUS_SUSPENDED, 'suspension_reason' => strip_tags($data['reason'])])->save();
        DB::table('sessions')->where('user_id', $user->id)->delete(); // force logout everywhere
        $user->tokens()->delete();
        $this->audit->log('user.suspended', $user, ['status' => 'active'], ['status' => 'suspended'], $data['reason']);

        return $this->ok(new UserResource($user->load('roles')), 'User suspended and signed out of all devices.');
    }

    public function activate(User $user): JsonResponse
    {
        $user->forceFill(['status' => User::STATUS_ACTIVE, 'suspension_reason' => null])->save();
        $this->audit->log('user.activated', $user, ['status' => 'suspended'], ['status' => 'active']);

        return $this->ok(new UserResource($user->load('roles')), 'User re-activated.');
    }

    /** Super admin only: create staff / partner accounts. */
    public function storeStaff(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:20'],
            'password' => ['required', Password::defaults()],
            'role' => ['required', 'in:admin,hotel-partner,customer'],
            'hotel_ids' => ['nullable', 'array'],
            'hotel_ids.*' => ['integer', 'exists:hotels,id'],
        ]);
        $user = DB::transaction(function () use ($data) {
            $user = User::query()->create(collect($data)->only(['name', 'email', 'phone', 'password'])->all());
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->roles()->sync([Role::query()->where('slug', $data['role'])->value('id')]);
            if ($data['role'] === 'hotel-partner') {
                $user->hotels()->sync($data['hotel_ids'] ?? []);
            }

            return $user;
        });
        $this->audit->log('user.created', $user, null, ['email' => $user->email, 'role' => $data['role']]);

        return $this->created(new UserResource($user->load('roles')), 'Account created.');
    }

    public function updateRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['roles' => ['required', 'array', 'min:1'], 'roles.*' => ['string', 'exists:roles,slug'], 'hotel_ids' => ['nullable', 'array'], 'hotel_ids.*' => ['integer', 'exists:hotels,id']]);
        if ($user->id === $request->user()->id) {
            return $this->fail('You cannot change your own roles.', 403, [], 'forbidden');
        }
        $old = $user->roles()->pluck('slug')->all();
        $user->roles()->sync(Role::query()->whereIn('slug', $data['roles'])->pluck('id'));
        if (in_array(Role::HOTEL_PARTNER, $data['roles'], true) && isset($data['hotel_ids'])) {
            $user->hotels()->sync($data['hotel_ids']);
        }
        $this->audit->log('user.roles_changed', $user, ['roles' => $old], ['roles' => $data['roles'], 'hotels' => $data['hotel_ids'] ?? null]);

        return $this->ok(new UserResource($user->load('roles')), 'Roles updated.');
    }

    public function roles(): JsonResponse
    {
        return $this->ok([
            'roles' => Role::query()->with('permissions:id,slug')->get()->map(fn ($r) => ['slug' => $r->slug, 'name' => $r->name, 'description' => $r->description,
                'permissions' => $r->slug === Role::SUPER_ADMIN ? ['*'] : $r->permissions->pluck('slug')]),
            'permissions' => collect(Permission::CATALOGUE)->map(fn ($v, $k) => ['slug' => $k, 'name' => $v[0], 'group' => $v[1]])->values(),
        ]);
    }

    public function updateRolePermissions(Request $request, string $slug): JsonResponse
    {
        $role = Role::query()->where('slug', $slug)->firstOrFail();
        if ($role->slug === Role::SUPER_ADMIN) {
            return $this->fail('Super admin always has every permission.', 422);
        }
        $data = $request->validate(['permissions' => ['present', 'array'], 'permissions.*' => ['string', 'exists:permissions,slug']]);
        $old = $role->permissions()->pluck('slug')->all();
        $role->permissions()->sync(Permission::query()->whereIn('slug', $data['permissions'])->pluck('id'));
        $this->audit->log('role.permissions_changed', $role, ['permissions' => $old], ['permissions' => $data['permissions']]);

        return $this->ok(null, 'Permissions updated.');
    }
}
