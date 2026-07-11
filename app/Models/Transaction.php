<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number', 'cashier_id', 'customer_id', 'payment_method',
        'payment_status', 'total_amount', 'paid_amount', 'change_due', 'due_date',
        'midtrans_order_id', 'midtrans_snap_token', 'midtrans_qr_url',
        'stock_deducted', 'paid_at', 'tx_date', 'bukti_pembayaran',
        'payment_fee_percentage', 'payment_fee_amount',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'change_due' => 'decimal:2',
        'due_date' => 'datetime',
    ];

    protected $appends = ['bukti_pembayaran_url'];

    public function getBuktiPembayaranUrlAttribute()
    {
        if (!$this->bukti_pembayaran) return null;
        if (str_starts_with($this->bukti_pembayaran, 'http')) return $this->bukti_pembayaran;
        return asset('storage/' . $this->bukti_pembayaran);
    }

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($transaction) {
            // Set unique temporary invoice number during insert to prevent race condition / duplicate key issues
            $transaction->invoice_number = 'TEMP_' . uniqid('', true) . '_' . microtime(true);
        });

        static::created(function ($transaction) {
            // Update to final invoice number based on the guaranteed unique auto-increment ID
            $transaction->invoice_number = 'INV/' . date('Ymd') . '/' . str_pad($transaction->id, 5, '0', STR_PAD_LEFT);
            $transaction->saveQuietly();
        });
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function details()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    public function receivablePayments()
    {
        return $this->hasMany(ReceivablePayment::class);
    }

    public function receivable()
    {
        return $this->hasOne(Receivable::class);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === 'paid';
    }

    public function isPartial(): bool
    {
        return $this->payment_status === 'partial';
    }

    public function isPending(): bool
    {
        return $this->payment_status === 'pending';
    }

    public function isReceivable(): bool
    {
        return $this->payment_method === 'receivable';
    }

    public function getRemainingDebt(): float
    {
        if (!$this->isReceivable()) {
            return 0;
        }
        
        return $this->remaining_balance;
    }

    // NOTE:
    // Kolom installment/DP seperti down_payment_amount, remaining_balance,
    // dan installment_count tidak tersedia di tabel `transactions` saat ini.
    // Method dihapus agar tidak memicu insert/update field yang tidak ada.

}