<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'type', 'recipient', 'subject', 'message', 'status', 'error_message'
    ];

    protected $casts = [
        'status' => 'string',
    ];
}