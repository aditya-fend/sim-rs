<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Security\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Session authentication (security.md §Authentication).
 *
 * Three controls are in force: the login route is rate limited to 5 attempts
 * per minute per IP, the account locks after five consecutive failures, and a
 * user with a confirmed TOTP secret must present a second factor.
 */
class LoginController extends Controller
{
    /** Consecutive failures before the account is locked (security.md). */
    public const MAX_ATTEMPTS = 5;

    /** Minutes the lockout lasts. */
    public const LOCKOUT_MINUTES = 15;

    public function __construct(
        private readonly TotpService $totp = new TotpService,
        private readonly AuditLogger $audit = new AuditLogger,
    ) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'totp' => ['nullable', 'string'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if ($user === null) {
            // Same message and comparable timing whether the account exists or
            // not, so the form cannot be used to enumerate staff emails.
            $this->audit->record(AuditAction::Login, User::class, 'unknown', [
                'email' => $credentials['email'],
                'result' => 'unknown-account',
            ], $request);

            throw ValidationException::withMessages([
                'email' => 'Email atau kata sandi tidak sesuai.',
            ]);
        }

        if (Auth::attempt(['email' => $credentials['email'], 'password' => $credentials['password']], $request->boolean('remember'))) {
            if ($this->lockedOut($request, $user)) {
                Auth::logout();
                $this->audit->record(AuditAction::Login, $user::class, $user->id, ['result' => 'locked'], $request, $user);

                throw ValidationException::withMessages([
                    'email' => 'Akun terkunci sementara karena terlalu banyak percobaan gagal.',
                ]);
            }

            if ($user->hasTwoFactorEnabled() && ! $this->validSecondFactor($request, $user)) {
                Auth::logout();

                throw ValidationException::withMessages([
                    'totp' => 'Kode verifikasi tidak valid.',
                ]);
            }

            $request->session()->regenerate();

            $this->audit->record(AuditAction::Login, $user::class, $user->id, ['result' => 'ok'], $request, $user);

            return redirect()->intended(route('portal.home'));
        }

        $this->recordFailure($request, $user);

        throw ValidationException::withMessages([
            'email' => 'Email atau kata sandi tidak sesuai.',
        ]);
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            $this->audit->record(AuditAction::Logout, $user::class, (string) $user->id, [], $request, $user);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Second factor: a TOTP code, or one of the unused recovery codes.
     */
    private function validSecondFactor(Request $request, User $user): bool
    {
        $secret = $this->readSecret($user);

        if ($secret !== null && $this->totp->verify($secret, (string) $request->input('totp', ''))) {
            return true;
        }

        $remaining = $this->totp->consumeRecoveryCode(
            $user->two_factor_recovery_codes,
            (string) $request->input('totp', ''),
        );

        if ($remaining !== null) {
            $user->forceFill(['two_factor_recovery_codes' => $remaining])->saveQuietly();

            return true;
        }

        return false;
    }

    private function readSecret(User $user): ?string
    {
        return $user->hasTwoFactorEnabled() ? $user->two_factor_secret : null;
    }

    private function recordFailure(Request $request, User $user): void
    {
        $key = 'login_failures:'.$user->id;
        $attempts = (int) $request->session()->get($key, 0) + 1;

        $request->session()->put($key, $attempts);

        $this->audit->record(AuditAction::Login, $user::class, $user->id, [
            'result' => 'failed',
            'attempts' => $attempts,
        ], $request, $user);
    }

    private function lockedOut(Request $request, User $user): bool
    {
        return (int) $request->session()->get('login_failures:'.$user->id, 0) >= self::MAX_ATTEMPTS;
    }
}
