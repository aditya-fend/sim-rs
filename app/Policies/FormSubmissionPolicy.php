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

    /**
     * Class-scope ability — see FormTemplatePolicy::manage().
     */
    public function createAny(User $user): bool
    {
        return $this->create($user);
    }

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
     * FR-11 / AC-08. Sign-off is DPJP-only, so a nurse cannot escalate to a doctor.
     *
     * This checks the *role* only. Whether the record is still signable is state,
     * not permission, and api-spec.md gives the two different statuses: 403 for a
     * user who may not sign, 409 for a record that is already LOCKED. Folding both
     * into one false here would answer 403 for an ordinary double-submit.
     */
    public function sign(User $user, FormSubmission $submission): bool
    {
        return $this->isDpjp($user);
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
