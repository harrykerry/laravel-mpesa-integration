<?php

namespace App\Support;

use App\Enums\C2bRejection;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * The one response envelope every API endpoint answers with.
 *
 * Success: {"status":"success","message":...,"data":...,"meta":...}
 * Failure: {"status":"error","message":...,"error_code":...,"errors":...}
 */
class ApiResponse
{
    /**
     * A successful response, with pagination metadata when there is any.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        int $status = HttpStatus::HTTP_OK,
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * A failed response.
     *
     * @param  array<string, mixed>  $errors  Field-level detail, when there is any.
     */
    public static function error(
        string $message,
        int $status = HttpStatus::HTTP_BAD_REQUEST,
        ?string $errorCode = null,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'status' => 'error',
            'message' => $message,
        ];

        if ($errorCode !== null) {
            $payload['error_code'] = $errorCode;
        }

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * A failed validation, in the same envelope as every other failure.
     */
    public static function invalid(Validator $validator, string $message = 'The request is not valid.'): JsonResponse
    {
        return self::error(
            message: $message,
            status: HttpStatus::HTTP_UNPROCESSABLE_ENTITY,
            errorCode: 'validation.failed',
            errors: $validator->errors()->toArray(),
        );
    }

    /**
     * The acknowledgement M-PESA expects from a callback it does not wait on.
     *
     * M-PESA resends anything it does not get a zero result code for, so a
     * callback is acknowledged as soon as it is recorded, before the queued
     * job that processes it has run.
     */
    public static function acknowledge(string $description = 'Accepted'): JsonResponse
    {
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => $description,
        ]);
    }

    /**
     * Tell M-PESA to complete a C2B payment it is holding.
     */
    public static function acceptPayment(): JsonResponse
    {
        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted',
        ]);
    }

    /**
     * Tell M-PESA to stop a C2B payment, so the customer is not deducted.
     */
    public static function rejectPayment(C2bRejection $rejection): JsonResponse
    {
        return response()->json([
            'ResultCode' => $rejection->value,
            'ResultDesc' => $rejection->description(),
        ]);
    }
}
