<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * Cached response for a replayed X-Idempotency-Key (api-spec.md §Idempotency).
 * Not in the original erd.md; recorded there as §11.
 *
 * @property string $id
 * @property string $key
 * @property string $endpoint
 * @property array<string, mixed> $response_body
 * @property int $response_status
 */
class IdempotencyKey extends Model
{
    use HasUuidKey;

    protected $fillable = [
        'user_id',
        'key',
        'endpoint',
        'response_body',
        'response_status',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_body' => 'array',
            'response_status' => 'integer',
            'expires_at' => 'datetime',
        ];
    }
}
