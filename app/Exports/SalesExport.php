<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class SalesExport implements FromCollection, WithHeadings, WithMapping, WithStyles
{
    protected $data;

    public function __construct($data)
    {
        $this->data = $data;
    }

    public function collection()
    {
        return collect($this->data['transactions']);
    }

    public function headings(): array
    {
        return [
            'No. Invoice',
            'Tanggal',
            'Kasir',
            'Total Pembayaran',
            'Metode Pembayaran',
            'Status',
        ];
    }

    public function map($transaction): array
    {
        $hasCategoryFilter = $this->data['has_category_filter'] ?? false;
        $amount = $hasCategoryFilter ? $transaction->filtered_amount : $transaction->total_amount;

        return [
            $transaction->invoice_number,
            $transaction->created_at->format('d/m/Y H:i:s'),
            $transaction->cashier->name ?? '-',
            'Rp ' . number_format($amount, 0, ',', '.'),
            $this->getPaymentMethodText($transaction->payment_method),
            $transaction->payment_status === 'paid' ? 'Lunas' : 'Belum Lunas',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }

    private function getPaymentMethodText($method)
    {
        return match($method) {
            'cash' => 'Tunai',
            'transfer' => 'Transfer Bank',
            'qris' => 'QRIS',
            'qris_statis' => 'QRIS',
            'midtrans_qris' => 'QRIS Midtrans',
            'receivable' => 'Piutang',
            default => $method,
        };
    }
}