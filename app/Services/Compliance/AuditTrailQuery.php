<?php

declare(strict_types=1);

namespace App\Services\Compliance;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Patient;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Audit trail queries for the Medical Records Officer (FR-14).
 *
 * Filters span date range, acting user, action type and the patient's medical
 * record number, exactly as FR-14 lists them. Because audit rows carry no
 * patient column, the RM filter resolves matching patients first and then
 * matches on the resource id recorded against them.
 */
final class AuditTrailQuery
{
    /**
     * @param  array{
     *     from?: string|null,
     *     to?: string|null,
     *     user_id?: string|null,
     *     action?: string|null,
     *     medical_record_number?: string|null,
     *     resource_type?: string|null
     * }  $filters
     */
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        return $this->query($filters)->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AuditLog>
     */
    public function query(array $filters)
    {
        return AuditLog::query()
            ->with('user')
            ->when($filters['from'] ?? null, function ($query, $from): void {
                $query->where('created_at', '>=', Carbon::parse($from)->startOfDay());
            })
            ->when($filters['to'] ?? null, function ($query, $to): void {
                $query->where('created_at', '<=', Carbon::parse($to)->endOfDay());
            })
            ->when($filters['user_id'] ?? null, fn ($query, $id) => $query->where('user_id', $id))
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($filters['resource_type'] ?? null, fn ($query, $type) => $query->where('resource_type', $type))
            ->when($filters['medical_record_number'] ?? null, function ($query, $mrn): void {
                $patientIds = $this->patientIdsFor($mrn);

                // A row about a patient is identified by resource_id; rows about
                // other resources are excluded rather than silently included.
                $query->where('resource_type', Patient::class)
                    ->whereIn('resource_id', $patientIds);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Every row matching the filters, for the PDF export. Bounded so a
     * pathological filter cannot stream the whole table into memory.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, AuditLog>
     */
    public function forExport(array $filters, int $limit = 5000): Collection
    {
        return $this->query($filters)->limit($limit)->get();
    }

    /**
     * The patients whose encounters this filter covers. An RM matches
     * submissions, assessments and SEPs alike.
     *
     * @return list<string>
     */
    private function patientIdsFor(string $medicalRecordNumber): array
    {
        return array_values(DB::table('patients')
            ->where('medical_record_number', $medicalRecordNumber)
            ->pluck('id')
            ->map(static fn (mixed $id): string => (string) $id)
            ->all());
    }

    /**
     * @return array<string, string>
     */
    public static function actionOptions(): array
    {
        $options = ['' => 'Semua aksi'];

        foreach (AuditAction::cases() as $case) {
            $options[$case->value] = $case->value;
        }

        return $options;
    }
}
