<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

/**
 * Every controller resolves authorization through policies (FR-15). The trait
 * supplies the `authorize()` helper; without it a stray call would surface as
 * an undefined-method 500 rather than a 403.
 */
abstract class Controller
{
    use AuthorizesRequests;
}
