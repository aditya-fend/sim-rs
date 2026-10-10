<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Encounter;
use App\Models\FormTemplate;
use App\Models\Patient;
use App\Services\Interoperability\Bpjs\BpjsSignature;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;

/**
 * The three screens user-flow.md's navigation map routes an administrator to
 * but that did not exist: the admin dashboard, the BPJS VClaim SEP simulator
 * (Flow 4) and the SatuSehat FHIR explorer.
 */
final class AdminToolsController extends Controller
{
    public function dashboard(): View
    {
        return view('admin.dashboard', [
            'templates' => FormTemplate::query()
                ->where('is_active', true)
                ->orderBy('code')
                ->get(),
            'patientCount' => Patient::query()->count(),
            'encounterCount' => Encounter::query()->count(),
        ]);
    }

    /**
     * FHIR explorer. Reads through the real gateway so what the screen shows
     * is exactly what an integrator would receive.
     */
    public function fhirExplorer(Request $request): View
    {
        $encounters = Encounter::query()->with('patient')->orderByDesc('admission_time')->limit(50)->get();

        $selected = null;

        if (is_string($request->query('encounter')) && $request->query('encounter') !== '') {
            $encounter = $encounters->firstWhere('id', $request->query('encounter'));

            if ($encounter instanceof Encounter) {
                $selected = [
                    'label' => 'Encounter',
                    'json' => $this->gatewayGet("/api/v1/fhir/Encounter/{$encounter->id}"),
                ];

                $conditions = $this->gatewayGet('/api/v1/fhir/Condition?encounter='.$encounter->id);

                if ($conditions['status'] === 200) {
                    $selected['conditions'] = $conditions['json'];
                }
            }
        }

        return view('admin.fhir-explorer', [
            'encounters' => $encounters,
            'selected' => $selected,
            'fhirToken' => (string) config('klinikform.fhir.token'),
        ]);
    }

    /**
     * BPJS VClaim simulator. The HMAC handshake is computed server-side: the
     * shared secret must never reach the browser, and a simulator is exactly
     * the place that would otherwise leak it.
     */
    public function bpjsSimulator(Request $request): View
    {
        $encounters = Encounter::query()->with('patient')->orderByDesc('admission_time')->limit(50)->get();

        $result = null;
        $payload = null;

        if ($request->isMethod('post')) {
            $validated = $request->validate([
                'noKartu' => ['required', 'string', 'regex:/^\d{13}$/'],
                'tglSep' => ['required', 'date'],
                'ppkPelayanan' => ['required', 'string', 'max:16'],
                'jnsPelayanan' => ['required', 'string', 'max:2'],
                'diagAwal' => ['required', 'string', 'max:16'],
                'poli_tujuan' => ['required', 'string', 'max:16'],
                'encounter_id' => ['nullable', 'uuid'],
                'catatan' => ['nullable', 'string', 'max:255'],
            ], [
                'noKartu.regex' => 'Nomor Kartu BPJS harus terdiri dari 13 digit.',
                'diagAwal.required' => 'diagAwal (kode ICD-10) wajib diisi.',
            ]);

            $payload = [
                't_sep' => array_filter([
                    'noKartu' => $validated['noKartu'],
                    'tglSep' => $validated['tglSep'],
                    'ppkPelayanan' => $validated['ppkPelayanan'],
                    'jnsPelayanan' => $validated['jnsPelayanan'],
                    'diagAwal' => $validated['diagAwal'],
                    'catatan' => $validated['catatan'] ?? null,
                    'poli' => ['tujuan' => $validated['poli_tujuan']],
                ]),
            ];

            $result = $this->callBpjs($payload, $validated['encounter_id'] ?? null);
        }

        return view('admin.bpjs-simulator', [
            'encounters' => $encounters,
            'payload' => $payload,
            'result' => $result,
            'consId' => (string) config('klinikform.bpjs.cons_id'),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, json: mixed}
     */
    private function callBpjs(array $payload, ?string $encounterId): array
    {
        $response = Http::withHeaders((new BpjsSignature)->headers())
            ->timeout(10)
            ->when($encounterId, fn ($request) => $request->withHeaders(['X-Encounter-Id' => $encounterId]))
            ->post(rtrim((string) config('app.url'), '/').'/api/v1/bpjs/vclaim/sep/insert', $payload);

        return ['status' => $response->status(), 'json' => $response->json()];
    }

    /**
     * @return array{status: int, json: mixed}
     */
    private function gatewayGet(string $uri): array
    {
        $response = Http::withToken((string) config('klinikform.fhir.token'))
            ->timeout(10)
            ->get(rtrim((string) config('app.url'), '/').$uri);

        return ['status' => $response->status(), 'json' => $response->json()];
    }
}
