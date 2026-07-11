<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BadProductExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data['bad_products']);
    }

    public function headings(): array
    {
        return [
            'No',
            'Nama Produk',
            'Kategori',
            'Jumlah',
            'Satuan',
            'Alasan Kerusakan',
            'Kerugian (Rp)',
            'Tanggal Insiden',
            'Status Laporan',
        ];
    }

    public function map($item): array
    {
        static $no = 1;
        return [
            $no++,
            $item['product_name'],
            $item['product_category'] ?? '-',
            $item['quantity'],
            $item['unit'] ?? '-',
            $item['damage_reason'] ?? '-',
            'Rp ' . number_format($item['loss_amount'] ?? 0, 0, ',', '.'),
            $item['incident_date_formatted'] ?? '-',
            $item['reported_status'] ?? '-',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}