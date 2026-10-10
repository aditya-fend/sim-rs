<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * erd.md `chk_audit_logs_action`.
 */
enum AuditAction: string
{
    case View = 'VIEW';
    case Create = 'CREATE';
    case Update = 'UPDATE';
    case Delete = 'DELETE';
    case Lock = 'LOCK';
    case Export = 'EXPORT';
    case Login = 'LOGIN';
    case Logout = 'LOGOUT';
}
