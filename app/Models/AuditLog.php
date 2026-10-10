<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only Permenkes No. 24/2022 access trail (erd.md §9, ADR-3).
 *
 * Immutability is enforced twice: this model refuses to persist a change or a
 * delete, and a database trigger raises if anything bypasses Eloquent (a raw
 * psql session, a queued job using a different connection, a future code path).
 *
 * @property int $id
 * @property string|null $user_id
 * @property string $role_name
 * @property AuditAction $action
 * @property string $resource_type
 * @property string $resource_id
 * @property string $ip_address
 * @property string $payload_checksum
 * @property CarbonInterface $created_at
 *

 * @use HasFactory<AuditLogFactory>
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'role_name',
        'action',
        'resource_type',
        'resource_id',
        'ip_address',
        'user_agent',
        'payload_checksum',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Insert only. Re-saving an existing row is a programming error, not a
     * runtime condition to recover from.
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new LogicException('audit_logs is append-only; an existing entry cannot be re-saved.');
        }

        return parent::save($options);
    }

    /**
     * No `destroy` on purpose (AC-08.2: "no update or destroy methods
     * exposed"). Callers that legitimately need to remove a row — migrations,
     * `migrate:fresh` — drop the table instead.
     */
    public function delete(): ?bool
    {
        throw new LogicException('audit_logs is append-only; entries cannot be deleted.');
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    public function scopeAction(Builder $query, AuditAction $action): Builder
    {
        return $query->where('action', $action->value);
    }
}
