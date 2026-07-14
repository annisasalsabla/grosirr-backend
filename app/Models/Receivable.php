<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Receivable extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id', 'customer_id', 'customer_name', 'customer_phone', 'customer_address',
        'total_debt', 'paid_amount', 'remaining_debt', 'due_date', 'status'
    ];

    protected $casts = [
        'total_debt' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_debt' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isOverdue()
    {
        return $this->due_date->isPast() && $this->status !== 'paid';
    }

    public function getProgressPercentage()
    {
        if ($this->total_debt <= 0) return 0;
        return ($this->paid_amount / $this->total_debt) * 100;
    }
    
    public function getDaysLeft()
    {
        if ($this->isOverdue()) {
            return -Carbon::now()->diffInDays($this->due_date);
        }
        return Carbon::now()->diffInDays($this->due_date);
    }
    
    public function getStatusLabel()
    {
        if ($this->status === 'paid') return 'Lunas';
        if ($this->isOverdue()) return 'Jatuh Tempo';
        if ($this->getDaysLeft() <= 1) return 'Segera Jatuh Tempo';

        return 'Belum Jatuh Tempo';
    }
}