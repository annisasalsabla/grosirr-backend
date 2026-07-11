<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payable extends Model
{
    use HasFactory;

    protected $fillable = [
        'supplier_id', 'total_debt', 'paid_amount', 'remaining_debt', 'due_date', 'status',
        'bukti_pembayaran', 'notes'
    ];

    protected $casts = [
        'total_debt' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'remaining_debt' => 'decimal:2',
        'due_date' => 'date',
    ];

    protected $appends = [
        'bukti_pembayaran_url'
    ];

    public function getBuktiPembayaranUrlAttribute()
    {
        if (!$this->bukti_pembayaran) return null;
        if (str_starts_with($this->bukti_pembayaran, 'http')) return $this->bukti_pembayaran;
        return asset('storage/' . $this->bukti_pembayaran);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function isOverdue()
    {
        return $this->due_date < now() && $this->status !== 'paid';
    }
}