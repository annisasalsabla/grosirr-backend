<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Stock extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'type', 'quantity', 'purchase_price', 'is_credit', 'due_date', 'description', 'user_id', 'supplier_id', 'bukti_pembelian',
        'source_type', 'related_bad_product_id'
    ];

    protected $casts = [
        'quantity' => 'integer',
    ];

    protected $appends = ['bukti_pembelian_url'];

    public function getBuktiPembelianUrlAttribute()
    {
        if (!$this->bukti_pembelian) return null;
        if (str_starts_with($this->bukti_pembelian, 'http')) return $this->bukti_pembelian;
        return asset('storage/' . $this->bukti_pembelian);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Scope: Stock masuk hari ini (menggunakan created_at dengan timezone Asia/Jakarta)
     */
    public function scopeTodayIn($query, ?string $category = null)
    {
        $today = now('Asia/Jakarta');
        $todayStart = $today->copy()->startOfDay();
        $todayEnd = $today->copy()->endOfDay();

        $query->whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('type', 'in');

        if ($category) {
            $query->whereHas('product', fn($q) => $q->where('category', $category));
        }

        return $query;
    }

    /**
     * Scope: Stock keluar hari ini (menggunakan created_at dengan timezone Asia/Jakarta)
     */
    public function scopeTodayOut($query, ?string $category = null)
    {
        $today = now('Asia/Jakarta');
        $todayStart = $today->copy()->startOfDay();
        $todayEnd = $today->copy()->endOfDay();

        $query->whereBetween('created_at', [$todayStart, $todayEnd])
            ->where('type', 'out');

        if ($category) {
            $query->whereHas('product', fn($q) => $q->where('category', $category));
        }

        return $query;
    }

    public function badProduct()
    {
        return $this->belongsTo(BadProduct::class, 'related_bad_product_id');
    }
}