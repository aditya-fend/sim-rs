<?php

return [

    /*
    |---------------------------------------------------------------------------
    | Field-level encryption (FR-08, ADR-2)
    |---------------------------------------------------------------------------
    | AES-256-GCM authenticated encryption for patient identity fields and
    | confidential anamnesis. The application master key is the primary entry in
    | `keys`; entries are addressed by the numeric version that prefixes every
    | ciphertext, which is what makes `klinikform:rotate-keys` possible without
    | a second storage column.
    */

    'encryption' => [
        'cipher' => 'aes-256-gcm',

        // 12-byte IV and 16-byte authentication tag, per security.md.
        'iv_bytes' => 12,
        'tag_bytes' => 16,

        // Active key = highest version present.
        //
        // APP_KEY is version 1. During a rotation the incoming key is added as
        // a higher version via KLINIKFORM_ENCRYPTION_KEYS (a JSON object of
        // {"2": "base64:..."}) while the retired key stays in APP_KEY until
        // `klinikform:rotate-keys` reports nothing left to re-encrypt.
        // `+` not array_merge: merge() renumbers integer keys, which would
        // silently collapse key versions 2, 3 … back onto 0, 1.
        'keys' => [1 => env('APP_KEY')]
            + (array) json_decode((string) env('KLINIKFORM_ENCRYPTION_KEYS', '{}'), true),
    ],

    /*
    |---------------------------------------------------------------------------
    | Blind index (FR-09, NFR-04)
    |---------------------------------------------------------------------------
    | Isolated pepper for the deterministic HMAC-SHA256 index that keeps an
    | encrypted NIK searchable in O(1). Deliberately a separate secret from
    | APP_KEY: compromising one must not reveal the other.
    */

    'blind_index' => [
        'pepper' => env('BLIND_INDEX_PEPPER'),
    ],

    /*
    |--------------------------------------------------------------------------
    | SatuSehat FHIR gateway (FR-16..FR-18)
    |--------------------------------------------------------------------------
    */

    'fhir' => [
        'token' => env('FAPI_TOKEN'),
        'patient_identifier_system' => 'https://fhir.kemkes.go.id/id/nik',
        'icd10_system' => 'http://hl7.org/fhir/sid/icd-10',
        'act_code_system' => 'http://terminology.hl7.org/CodeSystem/v3-ActCode',
        'clinical_status_system' => 'http://terminology.hl7.org/CodeSystem/condition-clinical',
        'encounter_identifier_system' => 'https://simrs.klinikform.local/encounter',
    ],

    /*
    |--------------------------------------------------------------------------
    | BPJS VClaim simulator (FR-19..FR-21)
    |--------------------------------------------------------------------------
    */

    'bpjs' => [
        'cons_id' => env('BPJS_CONS_ID'),
        'secret_key' => env('BPJS_SECRET_KEY'),
        'user_key' => env('BPJS_USER_KEY'),
        'faskes_code' => env('BPJS_FASKES_CODE', '0301R001'),

        // AC-07.3 / FR-20: reject anything drifting more than this.
        'timestamp_tolerance_seconds' => 300,

        // The simulator answers for a card only if its 13th digit is not 0.
        'inactive_card_final_digit' => '0',
    ],

    /*
    |--------------------------------------------------------------------------
    | Client draft buffer (AC-04, R-03)
    |--------------------------------------------------------------------------
    */

    'drafts' => [
        'local_storage_interval_seconds' => 5,
        'server_sync_interval_seconds' => 30,
        'ttl_hours' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency (api-spec.md §Idempotency)
    |--------------------------------------------------------------------------
    */

    'idempotency' => [
        'ttl_hours' => 24,
    ],

];
