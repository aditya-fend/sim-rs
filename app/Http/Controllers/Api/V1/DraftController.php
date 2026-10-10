<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\DraftSubmission;
use App\Models\Encounter;
use App\Services\Audit\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Server-side draft buffer (R-03, architecture.md §Caching strategy).
 *
 * Alpine.js writes to localStorage every 5 s; this endpoint receives the same
 * state every 30 s and gives a second line of defence against the shared
 * incognito workstations where localStorage is wiped on crash.
 *
 * Not in api-spec.md. Added as endpoint 9 and documented there.
 */
class DraftController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    /**
     * PUT /api/v1/drafts
     */
    public function upsert(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'encounter_id' => ['required', 'uuid', 'exists:encounters,id'],
            'form_template_id' => ['required', 'uuid', 'exists:form_templates,id'],
            'draft_payload' => ['required', 'array'],
        ]);

        $ttlHours = (int) config('klinikform.drafts.ttl_hours', 72);

        // The unique key on (encounter, template, user) makes this an upsert:
        // a nurse's rounds produce one row, not one per autosave tick.
        $draft = DraftSubmission::updateOrCreate(
            [
                'encounter_id' => $validated['encounter_id'],
                'form_template_id' => $validated['form_template_id'],
                'user_id' => $this->currentUser($request)->id,
            ],
            [
                'draft_payload' => $validated['draft_payload'],
                'expires_at' => now()->addHours($ttlHours),
            ],
        );

        return ApiResponse::success([
            'draft_id' => $draft->id,
            'expires_at' => $draft->expires_at->toIso8601ZuluString(),
        ], 'Draf tersinkronisasi.');
    }

    /**
     * GET /api/v1/drafts/{encounter_id}/{template_id}
     *
     * Offered on page load so the recovery banner can name the timestamp of the
     * draft it found even when localStorage was cleared.
     */
    public function show(Request $request, string $encounterId, string $templateId): JsonResponse
    {
        $draft = DraftSubmission::query()
            ->with('template')
            ->where('encounter_id', $encounterId)
            ->where('form_template_id', $templateId)
            ->where('user_id', $this->currentUser($request)->id)
            ->where('expires_at', '>', now())
            ->first();

        if ($draft === null) {
            return ApiResponse::notFound('No active server-side draft for this form.');
        }

        return ApiResponse::success([
            'draft_id' => $draft->id,
            'draft_payload' => $draft->draft_payload,
            'updated_at' => $draft->updated_at?->toIso8601ZuluString(),
            'expires_at' => $draft->expires_at->toIso8601ZuluString(),
        ]);
    }

    /**
     * DELETE /api/v1/drafts/{encounter_id}/{template_id}
     */
    public function destroy(Request $request, string $encounterId, string $templateId): JsonResponse
    {
        DraftSubmission::query()
            ->where('encounter_id', $encounterId)
            ->where('form_template_id', $templateId)
            ->where('user_id', $this->currentUser($request)->id)
            ->delete();

        return ApiResponse::success([], 'Draf dihapus.');
    }

    /**
     * GET /api/v1/encounters/{encounter}/drafts
     */
    public function indexForEncounter(Request $request, Encounter $encounter): JsonResponse
    {
        $drafts = $encounter->drafts()
            ->where('user_id', $this->currentUser($request)->id)
            ->where('expires_at', '>', now())
            ->with('template')
            ->get();

        $this->audit->record(
            AuditAction::View,
            DraftSubmission::class,
            $encounter->id,
            ['drafts' => $drafts->count()],
            $request,
        );

        return ApiResponse::success([
            'data' => $drafts->map(fn (DraftSubmission $draft): array => [
                'draft_id' => $draft->id,
                'form_template_id' => $draft->form_template_id,
                'form_template_code' => $draft->template?->code,
                'updated_at' => $draft->updated_at?->toIso8601ZuluString(),
                'expires_at' => $draft->expires_at->toIso8601ZuluString(),
            ])->all(),
        ]);
    }

    /**
     * Sweep expired drafts. Scheduled in routes/console.php.
     */
    public function purgeExpired(): int
    {
        return DraftSubmission::query()->where('expires_at', '<', now())->delete();
    }
}
