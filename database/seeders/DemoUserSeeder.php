<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Encounter;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * The four demo personas from user-flow.md, one per role, plus a synthetic
 * patient registry. Passwords are shared across personas on purpose: this is
 * portfolio/demo data, and a distinct password per account only makes the
 * README longer. Never run this seeder against a real hospital database.
 */
class DemoUserSeeder extends Seeder
{
    public const PASSWORD = 'password';

    /** @var array<string, array{name: string, email: string, roles: list<string>, sip: ?string, ihs: ?string}> */
    private const PERSONAS = [
        'siti' => [
            'name' => 'Siti Rahmawati, S.Kep.',
            'email' => 'siti@klinikform.local',
            'roles' => [RoleSeeder::NURSE],
            'sip' => null,
            'ihs' => null,
        ],
        'budi' => [
            'name' => 'Dr. Budi Setiawan, Sp.PD',
            'email' => 'budi@klinikform.local',
            'roles' => [RoleSeeder::DPJP],
            'sip' => '446/120/IP.D/2023',
            'ihs' => 'DR987654321',
        ],
        'rian' => [
            'name' => 'Rian Firmansyah',
            'email' => 'rian@klinikform.local',
            'roles' => [RoleSeeder::ADMIN],
            'sip' => null,
            'ihs' => null,
        ],
        'dewi' => [
            'name' => 'Dewi Lestari, A.Md.RMIK',
            'email' => 'dewi@klinikform.local',
            'roles' => [RoleSeeder::REKAM_MEDIS],
            'sip' => null,
            'ihs' => null,
        ],
    ];

    public function run(): void
    {
        foreach (self::PERSONAS as $persona) {
            $user = User::updateOrCreate(
                ['email' => $persona['email']],
                [
                    'name' => $persona['name'],
                    'password' => Hash::make(self::PASSWORD),
                    'sip_number' => $persona['sip'],
                    'ihs_practitioner_id' => $persona['ihs'],
                ],
            );

            $user->syncRoles($persona['roles']);
        }

        $this->seedPatients();
    }

    /**
     * A handful of realistic (synthetic) records so every screen in the portal
     * has something to render on first boot.
     */
    private function seedPatients(): void
    {
        $patients = [
            ['00-48-21', 'Tn. Joko Susanto', '1983-05-12', 'male', '3315012345670001', '0001234567890', 'P00012345678'],
            ['00-48-22', 'Ny. Sri Wahyuni', '1979-11-03', 'female', '3374092104830002', '0001234567891', 'P00012345679'],
            ['00-48-23', 'An. Rizky Ramadhan', '2021-02-19', 'male', '3374090902210003', '0001234567892', 'P00012345680'],
            ['00-48-24', 'Ibu Sri Wahyuni', '1955-07-30', 'female', '3374095507300004', '', 'P00012345681'],
        ];

        foreach ($patients as [$mrn, $name, $birth, $gender, $nik, $card, $ihs]) {
            if (Patient::query()->where('medical_record_number', $mrn)->exists()) {
                continue;
            }

            $patient = new Patient([
                'medical_record_number' => $mrn,
                'full_name' => $name,
                'birth_date' => $birth,
                'gender' => $gender,
                'ihs_patient_id' => $ihs,
                'bpjs_card_number' => $card !== '' ? $card : null,
            ]);

            // setNik() also derives nik_hash; passing both would let them drift.
            $patient->setNik($nik);
            $patient->setPhone('0812345678'.random_int(10, 99));
            $patient->setAddress('Jl. Merdeka No. '.random_int(1, 99).', Grobogan');
            $patient->save();
        }

        Encounter::query()->updateOrCreate(
            ['encounter_number' => 'ENC-202503-0012'],
            [
                'patient_id' => Patient::query()->where('medical_record_number', '00-48-21')->value('id'),
                'encounter_type' => 'EMER',
                'status' => 'arrived',
                'service_unit' => 'IGD',
                'admission_time' => now(),
            ],
        );
    }
}
