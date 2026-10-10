<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\FormSubmission;
use App\Models\FormTemplate;
use Database\Seeders\RoleSeeder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Role-aware landing page. Each persona from user-flow.md is sent to the
 * screen their role actually works in, rather than a menu they must navigate.
 */
class HomeController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $this->currentUser($request);

        $destination = match (true) {
            $user->hasRole(RoleSeeder::DPJP) => route('portal.doctor.worklist'),
            $user->can('createAny', FormSubmission::class) => route('portal.nurse.intake'),
            $user->can('manage', FormTemplate::class) => route('admin.schemas.index'),
            $user->can('viewAny', AuditLog::class) => route('compliance.audit.index'),
            default => null,
        };

        if ($destination !== null) {
            return view('portal.redirect', ['destination' => $destination]);
        }

        return view('portal.no-access', ['user' => $user]);
    }
}
