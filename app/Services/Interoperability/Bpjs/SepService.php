<?php

declare(strict_types=1);

namespace App\Services\Interoperability\Bpjs;

use App\Models\BpjsSepRecord;
use App\Models\Encounter;
use App\Services\Interoperability\Fhir\FhirTransformer;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * BPJS VClaim SEP (Surat Eligibilitas Peserta) creation simulator
 * (FR-21, AC-07.2).
 *
 * This is a staging simulator that mirrors the production VClaim 2.0 protocol;
 * it never talks to the live BPJS gateway (project-brief.md §Non-Goals).
 */
final class SepService
{
    public function __construct(
        private readonly BpjsSignature $signature = new BpjsSignature,
    ) {}

    /**
     * @param  array<string, mixed>  $tSep  The `t_sep` object from the request.
     * @return array{status: int, body: array<string, mixed>}
     */
    public function insertSep(Encounter $encounter, array $tSep, int|string $requestTimestamp): array
    {
        $cardNumber = (string) ($tSep['noKartu'] ?? '');
        $diagAwal = (string) ($tSep['diagAwal'] ?? '');
        $poli = (array) ($tSep['poli'] ?? []);

        // Card validity: the simulator treats a card ending in the configured
        // digit as suspended. user-flow.md §Flow 4 branches on exactly this.
        if ($cardNumber === '' || str_ends_with($cardNumber, (string) Config::get('klinikform.bpjs.inactive_card_final_digit'))) {
            return [
                'status' => 200,
                'body' => [
                    'metaData' => ['code' => '201', 'message' => 'Peserta Tidak Aktif'],
                    'response' => [
                        'message' => 'Nomor kartu BPJS tidak aktif atau tidak terdaftar.',
                    ],
                ],
            ];
        }

        $patient = $encounter->patient;
        $sepNumber = $this->nextSepNumber();

        $record = DB::transaction(function () use ($encounter, $patient, $tSep, $cardNumber, $poli, $diagAwal, $sepNumber, $requestTimestamp): BpjsSepRecord {
            $responseBody = [
                'metaData' => ['code' => '200', 'message' => 'Sukses'],
                'response' => [
                    'sep' => [
                        'noSep' => $sepNumber,
                        'tglSep' => (string) ($tSep['tglSep'] ?? now()->toDateString()),
                        'noKartu' => $cardNumber,
                        'nama' => $patient?->full_name,
                        'jnsPelayanan' => $this->serviceLabel($tSep),
                        'poli' => (string) ($poli['tujuan'] ?? 'IGD'),
                        'diagnosa' => $diagAwal !== '' ? $diagAwal.' - '.FhirTransformer::describe($diagAwal) : '',
                        'catatan' => (string) ($tSep['catatan'] ?? ''),
                    ],
                ],
            ];

            return BpjsSepRecord::create([
                'encounter_id' => $encounter->id,
                'patient_id' => $patient?->id ?? $encounter->patient_id,
                'sep_number' => $sepNumber,
                'bpjs_card_number' => $cardNumber,
                'poli_code' => (string) ($poli['tujuan'] ?? 'IGD'),
                'faskes_rujukan_code' => (string) ((array) ($tSep['rujukan'] ?? []))['ppkRujukan'] ?? null,
                'diagnosis_icd10' => $diagAwal,
                'signature_used' => $this->signature->sign(
                    (string) Config::get('klinikform.bpjs.cons_id'),
                    (string) Config::get('klinikform.bpjs.secret_key'),
                    $requestTimestamp,
                ),
                'request_timestamp' => (int) $requestTimestamp,
                'response_payload' => $responseBody,
            ]);
        });

        return ['status' => 201, 'body' => $record->response_payload];
    }

    /**
     * SEP number shape, taken from the concrete example in api-spec.md:
     *
     *     0301R001 0325 B 0000142
     *     ^faskes  ^MMYY ^ ^7-digit counter
     *
     * AC-07.2 calls this a "19-character" number while its own template and
     * example produce 20. The example wins; the constraint in the
     * bpjs_sep_records migration encodes the same shape.
     */
    public function nextSepNumber(): string
    {
        $faskes = (string) Config::get('klinikform.bpjs.faskes_code', '0301R001');
        $faskes = str_pad(substr($faskes, 0, 8), 8, '0');
        $stamp = now()->format('my');

        $usedToday = BpjsSepRecord::query()
            ->where('sep_number', 'like', $faskes.$stamp.'B%')
            ->count();

        $counter = str_pad((string) ($usedToday + 1), 7, '0', STR_PAD_LEFT);

        return $faskes.$stamp.'B'.$counter;
    }

    /**
     * @param  array<string, mixed>  $tSep
     */
    private function serviceLabel(array $tSep): string
    {
        // VClaim jnsPelayanan 1 = rawat jalan, 2 = rawat inap, 3 = LLP.
        return match ((string) ($tSep['jnsPelayanan'] ?? '2')) {
            '1' => 'Rawat Jalan',
            '3' => 'Rawat Jalan',
            default => 'Rawat Inap',
        };
    }
}
