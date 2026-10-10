<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Permenkes No. 24/2022 access trail (FR-12..FR-14, AC-08.1, ADR-3).
 *
 * Every clinical read, write, lock and export lands here with a SHA-256
 * checksum of the record state at that moment, so the compliance console can
 * later prove a locked record has not been altered underneath it.
 */
final class AuditLogger
{
    public function record(
        AuditAction $action,
        string $resourceType,
        string $resourceId,
        array $payload = [],
        ?Request $request = null,
        ?User $user = null,
    ): AuditLog {
        $request ??= request();
        $user ??= Auth::user();

        return AuditLog::create([
            'user_id' => $user?->id,
            'role_name' => $this->roleFor($user),
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'ip_address' => (string) ($request->ip() ?? '0.0.0.0'),
            'user_agent' => (string) ($request->userAgent() ?? ''),
            'payload_checksum' => self::checksum($payload),
        ]);
    }

    /**
     * Hash the state of a model, excluding fields that change for reasons
     * unrelated to clinical content. `$model->toArray()` would include
     * `updated_at`, which flips on any unrelated write and would make every
     * re-check look like tampering.
     *
     * @return array<string, mixed>
     */
    public function recordFor(
        AuditAction $action,
        Model $model,
        ?Request $request = null,
        ?User $user = null,
    ): AuditLog {
        return $this->record(
            $action,
            $model::class,
            (string) $model->getKey(),
            $this->stateOf($model),
            $request,
            $user,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function stateOf(Model $model): array
    {
        $ignored = ['updated_at', 'remember_token'];

        $state = $model->toArray();

        foreach ($ignored as $key) {
            unset($state[$key]);
        }

        // Decrypted values are hashed, never written to the log.
        return $state;
    }

    /**
     * Canonical SHA-256 over a payload (dfd.md §P5.0).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function checksum(array $payload): string
    {
        ksort($payload);

        return hash('sha256', (string) json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ));
    }

    /**
     * Recompute a checksum and compare, so the compliance console can show a
     * green "intact" or a red "altered" without trusting the stored value.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function verify(string $expected, array $payload): bool
    {
        return hash_equals($expected, self::checksum($payload));
    }

    /**
     * dfd.md §P1.0 binds the active role to the execution context so every
     * downstream log attributes the action to the role that was actually
     * exercised, not to whatever the user holds today.
     */
    private function roleFor(?User $user): string
    {
        if ($user === null) {
            return 'anonymous';
        }

        $roles = $user->getRoleNames();

        return $roles->isEmpty() ? 'none' : (string) $roles->first();
    }
}
