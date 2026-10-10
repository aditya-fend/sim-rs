<?php

declare(strict_types=1);

namespace App\Http\Controllers\Compliance;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Compliance\AuditTrailQuery;
use App\Services\Compliance\ComplianceReport;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * FR-14 — the Permenkes No. 24/2022 compliance console.
 *
 * Reachable only by the Medical Records Officer and the System Administrator
 * (AuditLogPolicy); everyone else gets 403 before this controller runs.
 */
class AuditTrailController extends Controller
{
    /**
     * resource_id is a UUID column, so the "someone looked at the trail"
     * events need a sentinel. The nil UUID is stable and can never collide
     * with a real clinical record.
     */
    private const TRAIL_SENTINEL = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly AuditTrailQuery $trail = new AuditTrailQuery,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $this->filters($request);

        $entries = $this->trail->paginate($filters);

        // Viewing the trail is itself auditable.
        $this->audit->record(
            AuditAction::View,
            self::class,
            self::TRAIL_SENTINEL,
            ['filters' => $filters, 'result_count' => $entries->total()],
            $request,
        );

        return view('compliance.audit-trail.index', [
            'entries' => $entries,
            'filters' => $filters,
            'actions' => AuditTrailQuery::actionOptions(),
            'users' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * E2E-05 — export the filtered trail as a signed compliance report.
     */
    public function export(Request $request): Response
    {
        $this->authorize('export', AuditLog::class);

        $filters = $this->filters($request);
        $entries = $this->trail->forExport($filters);

        $this->audit->record(
            AuditAction::Export,
            self::class,
            self::TRAIL_SENTINEL,
            ['filters' => $filters, 'exported' => $entries->count()],
            $request,
        );

        $report = new ComplianceReport;
        $pdf = $report->pdf($entries, $filters, (string) $this->currentUser($request)->name);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report->filename().'"',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return array_filter([
            'from' => $request->query('from'),
            'to' => $request->query('to'),
            'user_id' => $request->query('user_id'),
            'action' => $request->query('action'),
            'medical_record_number' => $request->query('medical_record_number'),
            'resource_type' => $request->query('resource_type'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }
}
