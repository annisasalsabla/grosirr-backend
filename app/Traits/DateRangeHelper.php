<?php

namespace App\Traits;

use Carbon\Carbon;

trait DateRangeHelper
{
    /**
     * Menghitung range tanggal untuk minggu ke-N berdasarkan rumus Flutter (Chunking 7-Hari).
     * Mencegah ketidakcocokan antara text dropdown "Minggu 4 (22-31)" dengan query database backend.
     * 
     * Minggu 1: Tanggal 1 s/d 7
     * Minggu 2: Tanggal 8 s/d 14
     * Minggu 3: Tanggal 15 s/d 21
     * Minggu 4+: Tanggal 22 s/d Akhir Bulan (28/29/30/31)
     */
    protected function getFlutterWeeklyRange($week, $month, $year)
    {
        $firstOfMonth = Carbon::createFromDate($year, $month, 1);
        $lastOfMonth  = $firstOfMonth->copy()->endOfMonth();

        $startDay = ($week - 1) * 7 + 1;
        
        if ($startDay > $lastOfMonth->day) {
            $startDate = $lastOfMonth->copy();
            $endDate   = $lastOfMonth->copy();
        } else {
            $startDate = $firstOfMonth->copy()->addDays($startDay - 1);
            
            // Minggu ke-4+ selalu menyerap sisa hari di bulan tersebut secara utuh
            if ($week >= 4) {
                $endDate = $lastOfMonth->copy();
            } else {
                $endDate = $startDate->copy()->addDays(6);
                if ($endDate->gt($lastOfMonth)) {
                    $endDate = $lastOfMonth->copy();
                }
            }
        }

        return [
            'start' => $startDate->toDateString(),
            'end'   => $endDate->toDateString()
        ];
    }
}
