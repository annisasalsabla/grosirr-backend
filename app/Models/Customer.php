<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'address', 'member_status',
        'calon_member_since', 'member_since', 'rejection_note'
    ];

    protected $casts = [
        'calon_member_since' => 'datetime',
        'member_since' => 'datetime',
    ];

    protected $appends = ['is_setia'];

    public function getIsSetiaAttribute()
    {
        return ($this->member_status ?? 'umum') === 'member';
    }

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function getUnpaidTransactions()
    {
        return $this->transactions()
            ->where('payment_method', 'receivable')
            ->where('payment_status', 'unpaid')
            ->get();
    }

    public function getTotalReceivable()
    {
        return $this->transactions()
            ->where('payment_method', 'receivable')
            ->where('payment_status', 'unpaid')
            ->sum('total_amount');
    }
}