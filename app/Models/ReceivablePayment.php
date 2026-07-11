<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ReceivablePayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id', 'amount_paid', 'payment_channel', 'paid_at', 'midtrans_transaction_id', 'payment_date', 'bukti_pembayaran'
    ];

    protected $appends = ['bukti_pembayaran_url'];

    public function getBuktiPembayaranUrlAttribute()
    {
        if (!$this->bukti_pembayaran) return null;
        if (str_starts_with($this->bukti_pembayaran, 'http')) return $this->bukti_pembayaran;
        return asset('storage/' . $this->bukti_pembayaran);
    }

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'paid_at' => 'datetime',
        'payment_date' => 'datetime',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }
}