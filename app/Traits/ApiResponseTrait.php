<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    protected function success($data = null, string $message = 'Berhasil', int $code = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'code' => $code,
        ], $code);
    }

    protected function error(string $message = 'Terjadi kesalahan', $errors = null, int $code = 400): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $code);
    }

    protected function validationError($errors, string $message = 'Validasi gagal'): JsonResponse
    {
        return $this->error($message, $errors, 422);
    }

    protected function notFound(string $message = 'Data tidak ditemukan'): JsonResponse
    {
        return $this->error($message, null, 404);
    }

    protected function unauthorized(string $message = 'Tidak memiliki akses'): JsonResponse
    {
        return $this->error($message, null, 403);
    }

    protected function unauthenticated(string $message = 'Silakan login terlebih dahulu'): JsonResponse
    {
        return $this->error($message, null, 401);
    }
}