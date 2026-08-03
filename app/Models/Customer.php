<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'address', 'credit_limit', 'user_id',
    ];

    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    public function receivables()
    {
        return $this->hasMany(Receivable::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
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