<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Carbon\CarbonInterface;
use Database\Factories\DraftSubmissionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Server-side twin of the browser localStorage draft buffer (erd.md §8).
 *
 * Exists because hospital shared workstations sometimes run in incognito mode
 * and evict localStorage (R-03). One row per (encounter, template, user).
 *
 * @property string $id
 * @property array<string, mixed> $draft_payload
 * @property CarbonInterface $expires_at
 *
 * @use HasFactory<DraftSubmissionFactory>
 */
class DraftSubmission extends Model
{
    use HasFactory;
    use HasUuidKey;

    protected $fillable = [
        'encounter_id',
        'form_template_id',
        'user_id',
        'draft_payload',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'draft_payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Encounter, $this> */
    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    /** @return BelongsTo<FormTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(FormTemplate::class, 'form_template_id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
