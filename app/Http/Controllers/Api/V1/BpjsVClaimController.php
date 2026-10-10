<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\BpjsSepRecord;
use App\Models\Encounter;
use App\Services\Audit\AuditLogger;
use App\Services\Interoperability\Bpjs\SepService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * api-spec.md §6 — BPJS VClaim SEP insertion simulator (FR-21, AC-07.2).
 *
 * Authentication is the HMAC handshake in BpjsSignatureAuth; this controller
 * only validates the payload and generates the SEP.
 */
class BpjsVClaimController extends Controller
{
    public function __construct(
        private readonly SepService $sepService = new SepService,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * POST /api/v1/bpjs/vclaim/sep/insert
     */
    public function insert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            't_sep.noKartu' => ['required', 'string', 'regex:/^\d{13}$/'],
            't_sep.tglSep' => ['required', 'date'],
            't_sep.ppkPelayanan' => ['required', 'string', 'max:16'],
            't_sep.jnsPelayanan' => ['required', 'string', 'max:2'],
            't_sep.noMR' => ['nullable', 'string', 'max:32'],
            't_sep.diagAwal' => ['required', 'string', 'max:16'],
            't_sep.poli.tujuan' => ['required', 'string', 'max:16'],
            't_sep.poli.eksekutif' => ['nullable', 'string', 'max:2'],
            't_sep.catatan' => ['nullable', 'string', 'max:255'],
            't_sep.noTelp' => ['nullable', 'string', 'max:20'],
            't_sep.user' => ['nullable', 'string', 'max:64'],
            't_sep.rujukan.ppkRujukan' => ['nullable', 'string', 'max:16'],
            't_sep.rujukan.noRujukan' => ['nullable', 'string', 'max:32'],
            't_sep.rujukan.tglRujukan' => ['nullable', 'date'],
        ], [
            't_sep.noKartu.regex' => 'Nomor Kartu BPJS harus terdiri dari 13 digit.',
            't_sep.diagAwal.required' => 'diagAwal (kode ICD-10) wajib diisi.',
        ]);

        $encounterId = $request->header('X-Encounter-Id');
        $encounter = is_string($encounterId) && $encounterId !== ''
            ? Encounter::query()->with('patient')->find($encounterId)
            : null;

        if (! $encounter instanceof Encounter) {
            return response()->json([
                'metaData' => [
                    'code' => '404',
                    'message' => 'Kunjungan (encounter) tidak ditemukan pada SIMRS.',
                ],
            ], 404);
        }

        $result = $this->sepService->insertSep(
            $encounter,
            (array) $validated['t_sep'],
            (int) $request->attributes->get('bpjs_timestamp', time()),
        );

        if ($result['status'] === 201) {
            $this->audit->record(
                AuditAction::Create,
                BpjsSepRecord::class,
                $encounter->id,
                ['sep' => $result['body']],
                $request,
            );
        }

        return response()->json($result['body'], $result['status']);
    }
}
