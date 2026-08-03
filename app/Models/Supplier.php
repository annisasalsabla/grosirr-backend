<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'address', 'phone', 'product_type', 'user_id'
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function payables()
    {
        return $this->hasMany(Payable::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}