<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\FormSubmission;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Clinical record access — the boundary Permenkes No. 24/2022 cares about
 * (FR-15). Three rules do the real work:
 *
 *  1. Sign-off is DPJP-only, so a nurse cannot escalate to a doctor.
 *  2. Rekam Medis is read-only on clinical content but owns export.
 *  3. Once LOCKED nothing may be edited, by anyone, including Admin — that
 *     is enforced again by a database trigger, because a policy alone is not
 *     a control.
 */
class FormSubmissionPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, ...RoleSeeder::ALL);
    }

    public function view(User $user, FormSubmission $submission): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->isNurse($user) || $this->isDpjp($user);
    }

    public function update(User $user, FormSubmission $submission): bool
    {
        if ($submission->isLocked()) {
            return false;
        }

        return $this->isNurse($user) || $this->isDpjp($user);
    }

    /**
     * FR-11 / AC-08. A submission may be signed exactly once, by a DPJP.
     * Re-signing returns false so the controller can answer 409 (api-spec.md).
     */
    public function sign(User $user, FormSubmission $submission): bool
    {
        return $this->isDpjp($user) && ! $submission->isLocked();
    }

    /**
     * FR-15: Admin "has no access to edit locked medical notes without an
     * emergency access audit flag". That flag does not exist in this build, so
     * the honest answer is a flat no.
     */
    public function emergencyAccess(User $user, FormSubmission $submission): bool
    {
        return false;
    }

    public function export(User $user, FormSubmission $submission): bool
    {
        return $this->isRekamMedis($user) || $this->isAdmin($user);
    }

    public function delete(User $user, FormSubmission $submission): bool
    {
        return false;
    }
}
