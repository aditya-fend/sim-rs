<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Response envelope for the internal REST endpoints.
 *
 * api-spec.md §"Error format" prescribes exactly this shape for failures.
 * The interoperability endpoints deliberately bypass it: /api/v1/fhir/* returns
 * a bare HL7 FHIR resource and /api/v1/bpjs/* returns the BPJS metaData
 * structure, both of which are dictated by their external specifications.
 */
final class ApiResponse
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function success(array $data = [], ?string $message = null, int $status = Response::HTTP_OK): JsonResponse
    {
        $body = ['success' => true, 'data' => $data];

        if ($message !== null) {
            $body = ['success' => true, 'message' => $message, 'data' => $data];
        }

        return response()->json($body, $status);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function created(array $data = [], ?string $message = null): JsonResponse
    {
        return self::success($data, $message, Response::HTTP_CREATED);
    }

    /**
     * @param  array<string, list<string>>  $details
     */
    public static function error(
        string $code,
        string $message,
        array $details = [],
        int $status = Response::HTTP_BAD_REQUEST,
    ): JsonResponse {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
            'timestamp' => now()->toIso8601ZuluString(),
        ], $status);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validationError(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return self::error('VALIDATION_ERROR', $message, $errors, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function unauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return self::error('UNAUTHENTICATED', $message, [], Response::HTTP_UNAUTHORIZED);
    }

    public static function forbidden(string $message = 'This action is unauthorized.'): JsonResponse
    {
        return self::error('FORBIDDEN', $message, [], Response::HTTP_FORBIDDEN);
    }

    /**
     * @param  array<string, mixed>  $metaData
     */
    public static function notFound(string $message = 'Resource not found.', array $metaData = []): JsonResponse
    {
        return self::error('NOT_FOUND', $message, $metaData, Response::HTTP_NOT_FOUND);
    }

    public static function conflict(string $message, string $code = 'CONFLICT'): JsonResponse
    {
        return self::error($code, $message, [], Response::HTTP_CONFLICT);
    }
}
