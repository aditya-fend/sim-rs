<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use RuntimeException;

/**
 * Raised when a sign-off targets a record that is already LOCKED.
 *
 * api-spec.md §3 maps this to HTTP 409 Conflict. It is a distinct exception
 * rather than a validation failure because the request is well-formed and
 * authorised — the *state* is what conflicts.
 */
class AlreadySignedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This assessment has already been signed and permanently locked.');
    }
}
