<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Interoperability\Bpjs\BpjsSignature;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * BPJS VClaim handshake authentication (FR-19, FR-20, AC-07.3).
 *
 * Rejects with HTTP 401 and the BPJS-prescribed body — not the internal
 * envelope — because the caller is a third-party system expecting BPJS's own
 * protocol, not ours.
 */
class BpjsSignatureAuth
{
    public function __construct(
        private readonly BpjsSignature $signature = new BpjsSignature,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $consId = (string) $request->header('X-cons-id', '');
        $timestamp = (string) $request->header('X-timestamp', '');
        $providedSignature = (string) $request->header('X-signature', '');

        if ($consId === '' || $timestamp === '' || $providedSignature === '') {
            return $this->reject('401', 'Header X-cons-id, X-timestamp, dan X-signature wajib diisi.');
        }

        $expectedConsId = (string) Config::get('klinikform.bpjs.cons_id');
        $secretKey = (string) Config::get('klinikform.bpjs.secret_key');

        if ($consId !== $expectedConsId) {
            return $this->reject('403', 'ConsID tidak dikenal.');
        }

        // AC-07.3: drift beyond ±300 s is rejected before the signature is even
        // checked, so a replayed request cannot be replayed at all.
        if (! $this->signature->isTimestampFresh($timestamp)) {
            return $this->reject('401', 'Timestamp kadaluarsa.');
        }

        if (! $this->signature->verify($consId, $secretKey, $providedSignature, $timestamp)) {
            return $this->reject('401', 'Signature tidak valid.');
        }

        if ((string) $request->header('user_key', '') !== (string) Config::get('klinikform.bpjs.user_key')) {
            return $this->reject('401', 'user_key tidak valid.');
        }

        // Downstream controllers record the handshake that was presented.
        $request->attributes->set('bpjs_cons_id', $consId);
        $request->attributes->set('bpjs_timestamp', (int) $timestamp);
        $request->attributes->set('bpjs_signature', $providedSignature);

        return $next($request);
    }

    private function reject(string $code, string $message): JsonResponse
    {
        return response()->json([
            'metaData' => [
                'code' => $code,
                'message' => $message,
            ],
        ], Response::HTTP_UNAUTHORIZED);
    }
}
