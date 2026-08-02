<?php

namespace App\Services;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ApiErrorResponseService
{
    public function unexpected(
        Throwable $exception,
        Request $request,
        array $context = [],
        int $status = 500,
    ): JsonResponse {
        report($exception);

        $reference = $request->attributes->get('error_reference');
        if (! is_string($reference) || $reference === '') {
            $reference = 'ERR-'.Str::upper(Str::random(10));
            $request->attributes->set('error_reference', $reference);
        }

        return response()->json(array_merge([
            'message' => 'Something went wrong. Please try again. If it continues, contact support with this reference.',
            'errorReference' => $reference,
        ], $context), max(500, $status));
    }
}
