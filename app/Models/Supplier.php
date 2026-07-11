<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'address', 'phone', 'product_type'
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function payables()
    {
        return $this->hasMany(Payable::class);
    }
}