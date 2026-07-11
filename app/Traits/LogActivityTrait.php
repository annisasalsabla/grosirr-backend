<?php

namespace App\Traits;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Request;

trait LogActivityTrait
{
    protected function logActivity($action, $description = null, $data = null)
    {
        try {
            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => $action,
                'description' => $description,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'data' => $data ? json_encode($data) : null,
            ]);
        } catch (\Exception $e) {
            // Silent fail, jangan ganggu proses utama
        }
    }
}