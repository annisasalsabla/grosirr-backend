<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 18px;
            font-weight: bold;
        }
        .subtitle {
            font-size: 12px;
        }
        .info {
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 10px;
        }
        .summary {
            margin-bottom: 20px;
            padding: 10px;
            background-color: #f9f9f9;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">GROSIR TIGA BERSAUDARA</div>
        <div class="subtitle">Jl. Rimbo Data, Bandar Buat, Padang</div>
        <div class="subtitle">Telp: 082181769006</div>
    </div>
    
    <div class="info">
        <strong>{{ $title }}</strong><br>
        Dicetak pada: {{ $generated_at }}
    </div>
    
    <div class="summary">
        <strong>RINGKASAN:</strong><br>
        Total Transaksi: {{ $summary['total_transactions'] ?? 0 }}<br>
        Total Pendapatan: Rp {{ number_format($summary['total_revenue'] ?? 0, 0, ',', '.') }}
    </div>
    
    <table>
        <thead>
            <tr>
                <th>No. Invoice</th>
                <th>Tanggal</th>
                <th>Kasir</th>
                <th>Total</th>
                <th>Metode</th>
            </tr>
        </thead>
        <tbody>
            @foreach($transactions as $transaction)
            <tr>
                <td>{{ $transaction->invoice_number }}</td>
                <td>{{ $transaction->created_at->format('d/m/Y H:i:s') }}</td>
                <td>{{ $transaction->cashier->name }}</td>
                <td>Rp {{ number_format($has_category_filter ? $transaction->filtered_amount : $transaction->total_amount, 0, ',', '.') }}</td>
                <td>
                    @switch($transaction->payment_method)
                        @case('cash') Tunai @break
                        @case('transfer') Transfer @break
                        @case('qris') QRIS @break
                        @case('midtrans_qris') QRIS Midtrans @break
                        @case('receivable') Piutang @break
                        @default {{ $transaction->payment_method }}
                    @endswitch
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>
    
    <div class="footer">
        Laporan ini dicetak secara otomatis oleh sistem<br>
        Grosir Tiga Bersaudara - {{ date('Y') }}
    </div>
</body>
</html>