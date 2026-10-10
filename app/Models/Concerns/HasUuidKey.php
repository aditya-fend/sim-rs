<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * erd.md gives every domain entity a UUIDv4 primary key. Generating in the
 * model (rather than relying on a database default) keeps inserts identical
 * across engines and lets a seeder know the id before the row exists.
 */
trait HasUuidKey
{
    /**
     * `$keyType` and `$incrementing` are set here rather than declared as
     * properties: Eloquent's base Model already declares `$keyType = 'int'`,
     * and a differing trait property is a fatal composition error. Leaving
     * `$incrementing` true is worse than it looks — Eloquent then adds an
     * implicit `id => int` cast and every retrieved UUID key becomes 0.
     */
    public function initializeHasUuidKey(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }

    public static function bootHasUuidKey(): void
    {
        static::creating(static function (Model $model): void {
            if (empty($model->getAttribute($model->getKeyName()))) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid());
            }
        });
    }
}
