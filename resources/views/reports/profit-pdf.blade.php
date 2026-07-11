<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 11px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .title {
            font-size: 16px;
            font-weight: bold;
        }
        .subtitle {
            font-size: 11px;
        }
        .info {
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 5px 7px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
            font-size: 10px;
        }
        td.number {
            text-align: right;
        }
        .footer {
            margin-top: 20px;
            text-align: center;
            font-size: 10px;
        }
        .summary {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f9f9f9;
            border: 1px solid #e0e0e0;
        }
        .summary table {
            width: auto;
            min-width: 350px;
        }
        .summary td {
            border: none;
            padding: 3px 8px;
        }
        .summary td:first-child {
            font-weight: bold;
        }
        .summary td:last-child {
            text-align: right;
        }
        .summary .divider td {
            border-top: 1px solid #ccc;
            padding-top: 5px;
        }
        .tag-realized {
            color: #155724;
            background-color: #d4edda;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 9px;
        }
        .tag-pending {
            color: #856404;
            background-color: #fff3cd;
            padding: 1px 4px;
            border-radius: 3px;
            font-size: 9px;
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
        <strong>RINGKASAN:</strong><br><br>
        <table>
            <tr>
                <td>Total Pendapatan Kotor</td>
                <td>:</td>
                <td>{{ $summary['total_pendapatan_kotor_formatted'] ?? ('Rp ' . number_format($summary['total_pendapatan_kotor'] ?? 0, 0, ',', '.')) }}</td>
            </tr>
            <tr>
                <td>Total Beban (Modal)</td>
                <td>:</td>
                <td>{{ $summary['total_beban_formatted'] ?? ('Rp ' . number_format($summary['total_beban'] ?? 0, 0, ',', '.')) }}</td>
            </tr>
            <tr class="divider">
                <td>Laba Bersih (Total)</td>
                <td>:</td>
                <td><strong>{{ $summary['total_profit_formatted'] ?? ('Rp ' . number_format($summary['total_profit'] ?? 0, 0, ',', '.')) }}</strong></td>
            </tr>
            <tr>
                <td>&nbsp;&nbsp;↳ Laba Terealisasi</td>
                <td>:</td>
                <td>{{ $summary['realized_profit_formatted'] ?? ('Rp ' . number_format($summary['realized_profit'] ?? 0, 0, ',', '.')) }}</td>
            </tr>
            <tr>
                <td>&nbsp;&nbsp;↳ Laba Tertunda</td>
                <td>:</td>
                <td>{{ $summary['pending_profit_formatted'] ?? ('Rp ' . number_format($summary['pending_profit'] ?? 0, 0, ',', '.')) }}</td>
            </tr>
        </table>
    </div>

    <table>
        <thead>
            <tr>
                <th>No</th>
                <th>Tanggal</th>
                <th>No. Invoice</th>
                <th>Produk</th>
                <th>Qty</th>
                <th>Penjualan (Rp)</th>
                <th>Modal (Rp)</th>
                <th>Laba (Rp)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            @if(isset($profits_detail) && count($profits_detail) > 0)
                @foreach($profits_detail as $index => $detail)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $detail['date'] }}</td>
                    <td>{{ $detail['invoice_number'] }}</td>
                    <td>{{ $detail['product_name'] }}</td>
                    <td class="number">{{ $detail['qty'] }}</td>
                    <td class="number">{{ $detail['total_penjualan_formatted'] ?? ('Rp ' . number_format($detail['total_penjualan'] ?? 0, 0, ',', '.')) }}</td>
                    <td class="number">{{ $detail['total_modal_formatted'] ?? ('Rp ' . number_format($detail['total_modal'] ?? 0, 0, ',', '.')) }}</td>
                    <td class="number">{{ $detail['profit_formatted'] ?? ('Rp ' . number_format($detail['profit'] ?? 0, 0, ',', '.')) }}</td>
                    <td>
                        @if($detail['status_label'] === 'Terealisasi')
                            <span class="tag-realized">Terealisasi</span>
                        @else
                            <span class="tag-pending">Tertunda</span>
                        @endif
                    </td>
                </tr>
                @endforeach
            @else
                <tr>
                    <td colspan="9" style="text-align: center;">Data tidak tersedia</td>
                </tr>
            @endif
        </tbody>
    </table>

    <div class="footer">
        Laporan ini dicetak secara otomatis oleh sistem<br>
        Grosir Tiga Bersaudara - {{ date('Y') }}
    </div>
</body>
</html>
