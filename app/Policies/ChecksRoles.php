<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Shared role predicates (FR-15, security.md §"Authorization model").
 *
 * Policies stay declarative about *what* a role may do; the question of
 * *whether* it holds the role is answered in exactly one place.
 */
trait ChecksRoles
{
    protected function hasRole(User $user, string ...$roles): bool
    {
        return $user->hasAnyRole($roles);
    }

    protected function isNurse(User $user): bool
    {
        return $this->hasRole($user, RoleSeeder::NURSE);
    }

    protected function isDpjp(User $user): bool
    {
        return $this->hasRole($user, RoleSeeder::DPJP);
    }

    protected function isRekamMedis(User $user): bool
    {
        return $this->hasRole($user, RoleSeeder::REKAM_MEDIS);
    }

    protected function isAdmin(User $user): bool
    {
        return $this->hasRole($user, RoleSeeder::ADMIN);
    }
}
