<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Sex;
use App\Models\Patient;
use App\Services\Security\BlindIndexer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registering a patient at the counter.
 *
 * Not in api-spec.md: the document specifies patient *lookup* but never the
 * creation path, even though every submission requires an encounter and every
 * encounter requires a patient. Invented as the smallest thing that makes the
 * system usable, and recorded in api-spec.md as endpoint 7.
 */
class StorePatientRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'medical_record_number' => ['required', 'string', 'max:32', 'unique:patients,medical_record_number'],
            'full_name' => ['required', 'string', 'max:255'],
            'birth_date' => ['required', 'date', 'before:today'],
            'gender' => ['required', Rule::enum(Sex::class)],
            'nik' => ['required', 'string', 'regex:/^\d{16}$/'],
            'phone' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'bpjs_card_number' => ['nullable', 'string', 'regex:/^\d{13}$/'],
            'ihs_patient_id' => ['nullable', 'string', 'max:64', 'unique:patients,ihs_patient_id'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nik.regex' => 'NIK harus terdiri dari 16 digit angka.',
            'bpjs_card_number.regex' => 'Nomor kartu BPJS harus terdiri dari 13 digit angka.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'medical_record_number' => 'nomor rekam medis',
            'full_name' => 'nama lengkap',
            'birth_date' => 'tanggal lahir',
            'bpjs_card_number' => 'nomor kartu BPJS',
        ];
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Patient::class) ?? false;
    }

    /**
     * Build the unsaved model. The NIK is set through the model rather than
     * mass-assigned so `nik_hash` is always derived from it.
     */
    public function patientAttributes(): Patient
    {
        $indexer = app(BlindIndexer::class);
        $nik = (string) $this->validated('nik');

        $patient = new Patient([
            'medical_record_number' => $this->validated('medical_record_number'),
            'full_name' => $this->validated('full_name'),
            'birth_date' => $this->validated('birth_date'),
            'gender' => $this->validated('gender'),
            'bpjs_card_number' => $this->validated('bpjs_card_number'),
            'ihs_patient_id' => $this->validated('ihs_patient_id'),
        ]);

        // Writing the NIK through the model also derives nik_hash, so the two
        // can never disagree.
        $patient->setNik($indexer->normalize($nik));
        $patient->setPhone($this->validated('phone'));
        $patient->setAddress($this->validated('address'));

        return $patient;
    }
}
