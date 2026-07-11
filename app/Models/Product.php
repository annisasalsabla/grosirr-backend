<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'category', 'unit', 'purchase_price', 'selling_price',
        'profit_per_unit', 'stock', 'min_stock', 'supplier_id',
        'unit_type', 'price_per_unit'
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'profit_per_unit' => 'decimal:2',
        'price_per_unit' => 'decimal:2',
        'stock' => 'integer',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function transactionDetails()
    {
        return $this->hasMany(TransactionDetail::class);
    }

    public function badProducts()
    {
        return $this->hasMany(BadProduct::class);
    }

    /**
     * Get price per unit (for sale)
     */
    public function getPricePerUnitAttribute()
    {
        // Jika price_per_unit sudah diset, gunakan itu
        if ($this->attributes['price_per_unit'] ?? false) {
            return $this->attributes['price_per_unit'];
        }
        // Fallback ke selling_price
        return $this->selling_price;
    }

    /**
     * Decrease stock
     */
    public function decreaseStock(int $quantity): bool
    {
        if ($this->stock < $quantity) {
            return false;
        }
        $this->decrement('stock', $quantity);
        return true;
    }

    /**
     * Increase stock
     */
    public function increaseStock(int $quantity): void
    {
        $this->increment('stock', $quantity);
    }

    /**
     * Check if stock is low
     */
    public function isLowStock(): bool
    {
        return $this->stock <= $this->min_stock;
    }

    /**
     * Get unit label in Indonesian
     */
    public function getUnitLabel(): string
    {
        if ($this->unit_type === 'karung') {
            return 'Karung';
        }
        
        // Untuk telur
        if ($this->unit === 'tray') {
            return 'Karpet/Tray';
        }
        
        return $this->unit ?? 'Unit';
    }
}