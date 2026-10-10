<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A hospital enterprise subscriber (api-spec.md §Webhooks). The secret is
 * stored as a SHA-256 digest; the plaintext is shown once at registration.
 *
 * @property string $id
 * @property string $url
 * @property list<string> $events
 * @property bool $is_active
 */
class WebhookEndpoint extends Model
{
    use HasUuidKey;

    /**
     * api-spec.md: clinical.submission.created, clinical.assessment.locked,
     * interoperability.satusehat.synced.
     *
     * @var list<string>
     */
    public const SUPPORTED_EVENTS = [
        'clinical.submission.created',
        'clinical.assessment.locked',
        'interoperability.satusehat.synced',
    ];

    protected $fillable = [
        'label',
        'url',
        'secret_hash',
        'events',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function subscribesTo(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events, true);
    }

    public static function hashSecret(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /** @return list<string> */
    public static function supportedEvents(): array
    {
        return self::SUPPORTED_EVENTS;
    }
}
