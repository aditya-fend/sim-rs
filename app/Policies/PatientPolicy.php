<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;
use Database\Seeders\RoleSeeder;

/**
 * Patient registry access. Every role may look a patient up — a triage nurse
 * cannot take an intake without identifying the patient first, and a medical
 * records officer must be able to trace an audit trail to a person.
 */
class PatientPolicy
{
    use ChecksRoles;

    public function viewAny(User $user): bool
    {
        return $this->hasRole($user, ...RoleSeeder::ALL);
    }

    public function view(User $user, Patient $patient): bool
    {
        return $this->viewAny($user);
    }

    /** Registering a patient is an intake task, not an administrative one. */
    public function create(User $user): bool
    {
        return $this->isNurse($user) || $this->isDpjp($user) || $this->isAdmin($user);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $this->isNurse($user) || $this->isDpjp($user) || $this->isAdmin($user);
    }

    /** Rekam Medis is read-only on clinical data by role definition. */
    public function delete(User $user, Patient $patient): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Seeing unmasked NIK / phone / address is a narrower grant than simply
     * seeing the record. It is still open to every clinical role because each
     * of them needs it to do their job; the mask is applied everywhere else.
     */
    public function viewSensitive(User $user, Patient $patient): bool
    {
        return $this->viewAny($user);
    }
}
