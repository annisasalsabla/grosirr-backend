<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'address', 'member_status',
        'calon_member_since', 'member_since', 'rejection_note', 'is_ambiguous'
    ];

    protected $casts = [
        'calon_member_since' => 'datetime',
        'member_since' => 'datetime',
        'is_ambiguous' => 'boolean',
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

    public static function evaluateMemberCandidacy($customerId)
    {
        $customer = self::where('id', $customerId)->lockForUpdate()->first();
        
        if ($customer && $customer->member_status === 'umum') {
            $stats = \Illuminate\Support\Facades\DB::table('transactions')
                ->where('customer_id', $customer->id)
                ->selectRaw('COUNT(*) as total_transaksi, SUM(total_amount) as total_belanja')
                ->first();

            $totalTransaksi = (int) $stats->total_transaksi;
            $totalBelanja = (float) ($stats->total_belanja ?? 0);

            if ($totalTransaksi >= 5 || $totalBelanja >= 500000) {
                $customer->update([
                    'member_status' => 'calon_member',
                    'calon_member_since' => now(),
                ]);
                return true;
            }
        }
        return false;
    }
}