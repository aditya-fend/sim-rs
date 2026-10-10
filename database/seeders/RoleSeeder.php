<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * FR-15 — the four pre-configured clinical roles. Role names are referenced
 * verbatim by the policies, the audit trail's `role_name` snapshot and
 * deployment scripts, so they must not be renamed without updating those.
 */
class RoleSeeder extends Seeder
{
    public const NURSE = 'Triage Nurse';

    public const DPJP = 'DPJP';

    public const REKAM_MEDIS = 'Rekam Medis';

    public const ADMIN = 'Admin';

    /** @var list<string> */
    public const ALL = [self::NURSE, self::DPJP, self::REKAM_MEDIS, self::ADMIN];

    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::ALL as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
