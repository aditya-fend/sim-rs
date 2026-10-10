<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedGcm;
use App\Models\Concerns\HasUuidKey;
use Illuminate\Database\Eloquent\Model;

/**
 * A hospital enterprise subscriber (api-spec.md §Webhooks). The shared secret
 * is encrypted at rest because outbound bodies must be signed with it.
 *
 * @property string $id
 * @property string $url
 * @property string $secret_encrypted
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
        'secret_encrypted',
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
            'secret_encrypted' => EncryptedGcm::class,
        ];
    }

    public function subscribesTo(string $event): bool
    {
        return $this->is_active && in_array($event, $this->events, true);
    }

    /** The plaintext shared secret, decrypted for outbound signing only. */
    public function secret(): string
    {
        return $this->secret_encrypted;
    }

    /** @return list<string> */
    public static function supportedEvents(): array
    {
        return self::SUPPORTED_EVENTS;
    }
}
