<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;

/**
 * Every controller resolves authorization through policies (FR-15). The trait
 * supplies the `authorize()` helper; without it a stray call would surface as
 * an undefined-method 500 rather than a 403.
 */
abstract class Controller
{
    use AuthorizesRequests;

    /**
     * The authenticated user.
     *
     * Every route reaching a controller is behind `auth:web` or the bearer
     * middleware, so the session is a precondition — but the framework types
     * it as nullable, and threading `?->` through each use site would push a
     * silent null into audit rows. This turns the precondition into one
     * explicit check.
     */
    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
