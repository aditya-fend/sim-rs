<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\DraftController;
use App\Http\Middleware\EnsureIdempotencyKey;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Maintenance schedule (deployment.md, erd.md §5)
|--------------------------------------------------------------------------
*/

// erd.md §5: expired drafts are swept; they exist only as a 72-hour crash
// buffer, so anything past its TTL is data the nurse never meant to keep.
Schedule::call(static fn (): int => app(DraftController::class)->purgeExpired())
    ->hourly()
    ->name('purge-expired-drafts');

// api-spec.md §Idempotency: keys are only meaningful for 24 hours.
Schedule::call(static fn (): int => EnsureIdempotencyKey::purgeExpired())
    ->daily()
    ->name('purge-idempotency-keys');
