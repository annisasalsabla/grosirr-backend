<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Profit extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'product_id',
        'quantity_sold',
        'profit_amount',
        'profit_date',
        'profit_date_only',
        'is_from_receivable',
        'receivable_status',
    ];


    protected $casts = [
        'profit_amount' => 'decimal:2',
        'quantity_sold' => 'integer',
        'profit_date' => 'date',
    ];

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}