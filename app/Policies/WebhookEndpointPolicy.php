<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WebhookEndpoint;

/**
 * An outbound endpoint is a standing exfiltration route for patient data, so
 * registering one is restricted to the deployment engineer (FR-15). No other
 * role can list, edit or remove a subscriber.
 */
class WebhookEndpointPolicy
{
    use ChecksRoles;

    /**
     * Class-scope ability, used by the route middleware:
     * `can:manage,App\Models\WebhookEndpoint` has no instance to hand over.
     */
    public function manage(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, WebhookEndpoint $webhookEndpoint): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, WebhookEndpoint $webhookEndpoint): bool
    {
        return $this->isAdmin($user);
    }
}
