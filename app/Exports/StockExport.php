<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StockExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data['products']);
    }

    public function headings(): array
    {
        return [
            'No',
            'Nama Produk',
            'Kategori',
            'Stok',
            'Satuan',
            'Min. Stok',
            'Harga Beli (Rp)',
            'Harga Jual (Rp)',
            'Status',
        ];
    }

    public function map($product): array
    {
        static $no = 1;
        return [
            $no++,
            $product['name'],
            $product['category_label'] ?? $product['category'],
            $product['stock'],
            $product['unit'] ?? '-',
            $product['min_stock'] ?? '-',
            'Rp ' . number_format($product['purchase_price'] ?? 0, 0, ',', '.'),
            'Rp ' . number_format($product['selling_price'] ?? 0, 0, ',', '.'),
            $product['status'] ?? 'Aman',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}