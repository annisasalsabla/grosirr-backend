<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Traits\ApiResponseTrait;

class CheckRole
{
    use ApiResponseTrait;

    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return $this->unauthenticated('Silakan login terlebih dahulu');
        }

        if (!in_array($user->role, $roles)) {
            return $this->unauthorized('Anda tidak memiliki izin untuk mengakses fitur ini');
        }

        if (!$user->is_active) {
            return $this->error('Akun Anda telah dinonaktifkan, silakan hubungi admin', null, 403);
        }

        return $next($request);
    }
}