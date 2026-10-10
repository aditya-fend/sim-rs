<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;

/**
 * FR-14: the audit console belongs to the Medical Records Officer (and, for
 * operational oversight, the System Administrator). Nobody else may read the
 * trail — including the clinicians whose actions it records.
 */
class AuditLogPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->isRekamMedis($user) || $this->isAdmin($user);
    }

    public function view(User $user, AuditLog $auditLog): bool
    {
        return $this->viewAny($user);
    }

    public function export(User $user): bool
    {
        return $this->isRekamMedis($user);
    }

    public function create(User $user): bool
    {
        // The application writes audit rows through AuditLogger with its own
        // insert path; no user-facing route may mint one directly.
        return false;
    }

    public function update(User $user, AuditLog $auditLog): bool
    {
        return false;
    }

    public function delete(User $user, AuditLog $auditLog): bool
    {
        return false;
    }
}
