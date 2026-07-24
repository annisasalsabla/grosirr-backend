<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BadProduct extends Model
{
    use HasFactory;

    protected $fillable = [
        'product_id', 'quantity', 'unit', 'damage_reason', 'image', 'loss_amount',
        'incident_date', 'tanggal_kejadian', 'reported_by', 'reported_to_supplier', 'status', 'reported_at',
        'status_kompensasi', 'tanggal_kompensasi', 'catatan_kompensasi', 'jumlah_kompensasi_uang',
        'compensated_quantity', 'compensated_value'
    ];

    protected $appends = ['image_url', 'catatan_kompensasi_history', 'display_status'];

    protected $hidden = ['catatan_kompensasi'];

    protected $casts = [
        'loss_amount' => 'decimal:2',
        'jumlah_kompensasi_uang' => 'decimal:2',
        'incident_date' => 'date',
        'tanggal_kejadian' => 'date',
        'tanggal_kompensasi' => 'date',
        'reported_to_supplier' => 'boolean',
        'status' => 'string',
        'reported_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function reportedBy()
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function getImageUrlAttribute()
    {
        if (!$this->image) return null;
        if (str_starts_with($this->image, 'http')) return $this->image;
        return asset('storage/' . $this->image);
    }

    public function getDisplayStatusAttribute()
    {
        if ($this->status_kompensasi === 'selesai') {
            return 'selesai';
        } elseif ($this->status_kompensasi === 'diganti_sebagian' || $this->reported_to_supplier || $this->status === 'reported') {
            return 'menunggu_kompensasi';
        } else {
            return 'belum_dilaporkan';
        }
    }

    public function getCatatanKompensasiHistoryAttribute()
    {
        if (empty($this->catatan_kompensasi)) {
            return [];
        }
        
        $json = json_decode($this->catatan_kompensasi, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        
        // Fallback legacy data
        return [
            [
                'tanggal' => $this->tanggal_kompensasi ? $this->tanggal_kompensasi->format('Y-m-d') : null,
                'jenis' => 'legacy',
                'nominal' => null,
                'jumlah' => null,
                'unit' => null,
                'catatan' => $this->catatan_kompensasi,
                'image_url' => null,
            ]
        ];
    }

    public function appendKompensasiHistory(array $newEntry)
    {
        $history = $this->catatan_kompensasi_history;
        $history[] = $newEntry;
        $this->catatan_kompensasi = json_encode($history);
    }

    /**
     * Kalkulasi status dan sisa hutang kompensasi
     * Mengembalikan array: ['status' => string, 'sisa_nilai' => float]
     */
    public static function calculateCompensationState($badProduct)
    {
        if ($badProduct->quantity <= 0 || $badProduct->loss_amount <= 0) {
            return [
                'status' => 'belum_diganti',
                'sisa_nilai' => $badProduct->loss_amount
            ];
        }

        $unitValue = $badProduct->loss_amount / $badProduct->quantity;
        $totalResolved = ($badProduct->compensated_quantity * $unitValue) + $badProduct->compensated_value;
        $sisaNilai = max(0, $badProduct->loss_amount - $totalResolved);
        
        $status = 'belum_diganti';
        if ($totalResolved >= $badProduct->loss_amount) {
            $status = 'selesai';
        } elseif ($totalResolved > 0) {
            $status = 'diganti_sebagian';
        }

        return [
            'status' => $status,
            'sisa_nilai' => $sisaNilai,
        ];
    }
}