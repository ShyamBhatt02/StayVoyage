<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permission::CATALOGUE as $slug => [$name, $group]) {
            Permission::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'group' => $group]);
        }

        $roles = [
            Role::SUPER_ADMIN => ['Super Admin', 'Full platform access, including roles, settings and finance.'],
            Role::ADMIN => ['Admin / Staff', 'Day-to-day operations: hotels, bookings, members, tours, content.'],
            Role::CUSTOMER => ['Customer', 'Books hotels, buys memberships and builds trips.'],
            Role::HOTEL_PARTNER => ['Hotel Partner', 'Manages only their own hotel(s): rooms, pricing, availability, bookings.'],
        ];
        foreach ($roles as $slug => [$name, $desc]) {
            Role::query()->updateOrCreate(['slug' => $slug], ['name' => $name, 'description' => $desc, 'is_system' => true]);
        }

        // Staff get operational permissions; role/permission management and platform settings stay with super admins.
        $staff = array_diff(array_keys(Permission::CATALOGUE), ['roles.manage', 'settings.manage']);
        Role::query()->where('slug', Role::ADMIN)->first()->permissions()->sync(Permission::query()->whereIn('slug', $staff)->pluck('id'));
    }
}
