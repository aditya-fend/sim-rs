<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Casts\EncryptedGcm;
use App\Models\Patient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Envelope key rotation (PRD R-01, security.md §"Incident response").
 *
 * Re-encrypts every ciphertext column under the current active key while the
 * previous key stays configured, so the run is reversible: migrated rows carry
 * the new version prefix, the rest still decrypt under the old one. Drop the
 * retired key from `KLINIKFORM_ENCRYPTION_KEYS` only once the count hits zero.
 *
 *   php artisan klinikform:rotate-keys --dry-run
 *   php artisan klinikform:rotate-keys
 */
class RotateKeysCommand extends Command
{
    /**
     * Every ciphertext column in the system. All are cast by EncryptedGcm, so
     * reading through the model and writing back re-seals them automatically.
     *
     * @var array<string, list<string>>
     */
    private const TARGETS = [
        'patients' => ['nik_encrypted', 'phone_encrypted', 'address_encrypted'],
        'form_submissions' => ['encrypted_anamnesis'],
        'users' => ['two_factor_recovery_codes'],
    ];

    protected $signature = 'klinikform:rotate-keys
                            {--dry-run : Report what would change without writing}
                            {--chunk=200 : Rows per transaction}';

    protected $description = 'Re-encrypt stored ciphertext under the active AES-256-GCM key (PRD R-01)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));
        $active = $this->activeVersion();

        $this->components->info("Active key version: v{$active}");

        $total = 0;

        foreach (self::TARGETS as $table => $columns) {
            $stale = $this->staleCount($table, $columns, $active);

            if ($stale === 0) {
                continue;
            }

            $this->components->twoColumnDetail($table, "{$stale} row(s) on an older key");
            $total += $stale;

            if (! $dryRun) {
                $this->rotateTable($table, $columns, $active, $chunk);
            }
        }

        if ($total === 0) {
            $this->components->info('All ciphertext is already on the active key.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->components->warn("Dry run: {$total} row(s) would be re-encrypted. Re-run without --dry-run to apply.");

            return self::SUCCESS;
        }

        $this->components->info("Re-encrypted {$total} row(s) onto v{$active}.");

        return self::SUCCESS;
    }

    /**
     * A row is stale when any ciphertext column exists and does not already
     * carry the active version prefix.
     *
     * @param  list<string>  $columns
     */
    private function staleCount(string $table, array $columns, int $active): int
    {
        // Every column goes inside one nested group: a bare orWhere() chain
        // would escape the outer WHERE and match any row with a non-null
        // ciphertext, whatever version it carries.
        return DB::table($table)
            ->where(static function ($outer) use ($columns, $active): void {
                foreach ($columns as $column) {
                    $outer->orWhere(static function ($inner) use ($column, $active): void {
                        $inner->whereNotNull($column)
                            ->where($column, 'not like', "v{$active}.%");
                    });
                }
            })
            ->count();
    }

    /**
     * @param  list<string>  $columns
     */
    private function rotateTable(string $table, array $columns, int $active, int $chunk): void
    {
        $cast = new EncryptedGcm;
        // The cast never reads model state; a throwaway instance is enough to
        // invoke get()/set() outside Eloquent's attribute pipeline.
        $model = new Patient;

        DB::table($table)->select(array_merge(['id'], $columns))->orderBy('id')->chunk(
            $chunk,
            function ($rows) use ($table, $columns, $cast, $model): void {
                DB::transaction(function () use ($table, $columns, $cast, $model, $rows): void {
                    foreach ($rows as $row) {
                        $updates = [];

                        foreach ($columns as $column) {
                            $value = $row->{$column};

                            if (! is_string($value) || ! str_starts_with($value, 'v')) {
                                continue;
                            }

                            $sealed = $cast->reseal($model, $column, $value, []);

                            if ($sealed[$column] !== $value) {
                                $updates[$column] = $sealed[$column];
                            }
                        }

                        if ($updates !== []) {
                            DB::table($table)->where('id', '=', $row->id)->update($updates);
                        }
                    }
                });
            },
        );
    }

    private function activeVersion(): int
    {
        /** @var array<int, string> $keys */
        $keys = array_filter((array) Config::get('klinikform.encryption.keys', []));

        if ($keys === []) {
            return 1;
        }

        return max(array_keys($keys));
    }
}
