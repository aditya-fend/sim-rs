<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\EncryptedGcm;
use App\Models\Concerns\HasUuidKey;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Hospital personnel (erd.md §1). erd.md gives users a UUID primary key,
 * which means Authenticatable must not assume an auto-increment id.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string|null $sip_number
 * @property string|null $two_factor_secret
 * @property CarbonInterface|null $two_factor_secret_confirmed_at
 * @property string|null $two_factor_recovery_codes
 *

 * @use HasFactory<UserFactory>
 */
class User extends Authenticatable
{
    use HasFactory;
    use HasRoles;
    use HasUuidKey;
    use Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'sip_number',
        'nik_hash',
        'ihs_practitioner_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'two_factor_secret' => EncryptedGcm::class,
            'two_factor_secret_confirmed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Encounters where this clinician is the attending DPJP.
     *
     * @return HasMany<Encounter, $this>
     */
    public function dpjpEncounters(): HasMany
    {
        return $this->hasMany(Encounter::class, 'dpjp_user_id');
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret_confirmed_at !== null;
    }

    /**
     * Name plus SIP line, as printed on the Permenkes signature stamp
     * (ui-spec.md `c-signature-stamp`).
     */
    public function signatureLine(): string
    {
        return $this->sip_number !== null && $this->sip_number !== ''
            ? "{$this->name} — SIP: {$this->sip_number}"
            : $this->name;
    }
}
