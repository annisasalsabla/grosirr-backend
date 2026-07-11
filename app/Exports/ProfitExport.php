<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProfitExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data['profits']);
    }

    public function headings(): array
    {
        return [
            'No',
            'Periode Tanggal',
            'Total Omzet Jual (Rp)',
            'Total Modal Barang (Rp)',
            'Keuntungan Bersih/Laba (Rp)',
            'Laba Terealisasi (Rp)',
            'Laba Tertunda (Rp)',
        ];
    }

    public function map($profit): array
    {
        static $no = 1;
        return [
            $no++,
            $profit['profit_date'],
            'Rp ' . number_format($profit['omzet_jual'] ?? 0, 0, ',', '.'),
            'Rp ' . number_format($profit['modal_barang'] ?? 0, 0, ',', '.'),
            'Rp ' . number_format($profit['profit_amount'] ?? 0, 0, ',', '.'),
            'Rp ' . number_format($profit['realized_profit'] ?? 0, 0, ',', '.'),
            'Rp ' . number_format($profit['pending_profit'] ?? 0, 0, ',', '.'),
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}