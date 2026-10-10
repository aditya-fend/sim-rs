<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Encounter;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Encounter visibility. Every clinical role may see the visit list; there is
 * no finer distinction to draw until the records inside it are reached, and
 * those carry their own policies.
 */
class EncounterPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, ...RoleSeeder::ALL);
    }

    public function view(User $user, Encounter $encounter): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isNurse($user) || $this->isDpjp($user) || $this->isAdmin($user);
    }

    /** Closing a visit is a clinical act, so it is not the records officer's. */
    public function update(User $user, Encounter $encounter): bool
    {
        return $this->isNurse($user) || $this->isDpjp($user) || $this->isAdmin($user);
    }
}
