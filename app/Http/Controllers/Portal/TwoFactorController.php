<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Services\Security\TotpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * security.md §MFA — RFC 6238 enrolment for the signed-in clinician.
 *
 * Two steps on purpose: the secret is stored before it is confirmed so a code
 * can be checked against it, and `two_factor_secret_confirmed_at` is what the
 * login challenge looks at. A half-enrolled account still logs in with a
 * password, which is recoverable; a wrongly confirmed one is not.
 */
final class TwoFactorController extends Controller
{
    public function __construct(private readonly TotpService $totp) {}

    public function show(Request $request): View
    {
        $user = $this->currentUser($request);

        return view('portal.two-factor', [
            'confirmed' => $user->two_factor_secret_confirmed_at !== null,
            'recoveryCodes' => null,
            'provisioningUri' => null,
        ]);
    }

    /**
     * Issue a candidate secret and hand back the otpauth:// URI. Nothing is
     * confirmed until /confirm accepts a code generated from it.
     */
    public function start(Request $request): RedirectResponse
    {
        $user = $this->currentUser($request);

        $secret = $this->totp->generateSecret();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_secret_confirmed_at' => null,
        ])->save();

        return back()->with('provisioning_uri', $this->totp->provisioningUri($user->email, $secret));
    }

    /**
     * Confirm the candidate secret and issue recovery codes. The codes are
     * stored hashed and shown exactly once.
     */
    public function confirm(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ], [
            'code.regex' => 'Kode autentikator terdiri dari 6 digit.',
        ]);

        $user = $this->currentUser($request);
        $secret = $user->two_factor_secret;

        if (! is_string($secret) || $secret === '') {
            return back()->withErrors(['code' => 'Belum ada rahasia TOTP. Mulai enrolment kembali.']);
        }

        if (! $this->totp->verify($secret, $validated['code'])) {
            return back()->withErrors(['code' => 'Kode tidak cocok atau sudah kedaluwarsa.']);
        }

        $codes = $this->totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret_confirmed_at' => now(),
            'two_factor_recovery_codes' => $this->totp->hashRecoveryCodes($codes),
        ])->save();

        return back()->with('recovery_codes', $codes);
    }

    /**
     * Turn MFA off. Deliberately re-asks for the password: a hijacked session
     * should not be able to silently downgrade a clinician's login.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
        ], [
            'current_password.current_password' => 'Kata sandi tidak cocok.',
        ]);

        $this->currentUser($request)->forceFill([
            'two_factor_secret' => null,
            'two_factor_secret_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return back()->with('status', 'Autentikasi dua faktor dinonaktifkan.');
    }
}
