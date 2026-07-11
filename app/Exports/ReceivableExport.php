<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ReceivableExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data['receivables']);
    }

    public function headings(): array
    {
        return [
            'No',
            'No. Invoice',
            'Nama Pelanggan',
            'Telepon',
            'Total Hutang (Rp)',
            'Sudah Dibayar (Rp)',
            'Sisa Hutang (Rp)',
            'Jatuh Tempo',
            'Status',
        ];
    }

    public function map($receivable): array
    {
        static $no = 1;
        return [
            $no++,
            $receivable['invoice_number'] ?? '-',
            $receivable['customer_name'],
            $receivable['customer_phone'] ?? '-',
            'Rp ' . number_format($receivable['total_debt'] ?? 0, 0, ',', '.'),
            'Rp ' . number_format($receivable['paid_amount'] ?? 0, 0, ',', '.'),
            'Rp ' . number_format($receivable['remaining_debt'] ?? 0, 0, ',', '.'),
            $receivable['due_date_formatted'] ?? '-',
            $receivable['status_label'] ?? $receivable['status'],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}