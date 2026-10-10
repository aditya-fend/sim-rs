<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormTemplate;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Schema management is the SIMRS deployment engineer's job (FR-15). Everyone
 * else reads the active schema, since the rendered form comes from it.
 */
class FormTemplatePolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, ...RoleSeeder::ALL);
    }

    public function view(User $user, FormTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    /** FR-02 / FR-15: publishing a version inserts a new immutable row. */
    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    /** Rows are append-only, so "update" means "publish a successor version". */
    public function update(User $user, FormTemplate $template): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, FormTemplate $template): bool
    {
        // Historical submissions reference this row; deleting it would orphan
        // the evidence chain that makes AC-01.3 possible.
        return false;
    }
}
