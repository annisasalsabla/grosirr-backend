<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laporan Barang Rusak - {{ $supplier->name }}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Arial', sans-serif;
            font-size: 11px;
            line-height: 1.3;
            padding: 25px;
        }
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #333;
            padding-bottom: 5px;
        }
        .title {
            font-size: 16px;
            font-weight: bold;
        }
        .subtitle {
            font-size: 10px;
            color: #555;
        }
        .report-info table {
            width: 100%;
            margin-bottom: 10px;
            font-size: 10px;
        }
        .label {
            font-weight: bold;
            width: 90px;
        }
        .supplier-info {
            margin-bottom: 15px;
            padding: 8px;
            background-color: #f0f7fa;
            border-radius: 4px;
            border-left: 3px solid #2196F3;
        }
        /* Desain Summary Card Sesuai Request: Minimalis & Tidak Terlalu Besar */
        .summary-box {
            margin-bottom: 15px;
            width: 100%;
        }
        .summary-table {
            width: 100%;
            border-collapse: collapse;
        }
        .summary-table td {
            border: 1px solid #ddd;
            padding: 6px;
            text-align: center;
            background-color: #f9f9f9;
        }
        .summary-table .value {
            font-size: 13px;
            font-weight: bold;
            color: #2196F3;
        }
        .summary-table .title-sm {
            font-size: 10px;
            color: #666;
        }
        table.data-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table.data-table th, table.data-table td {
            border: 1px solid #ccc;
            padding: 6px;
            text-align: left;
            vertical-align: middle;
        }
        table.data-table th {
            background-color: #2196F3;
            color: white;
            font-weight: bold;
            font-size: 10px;
        }
        tr:nth-child(even) {
            background-color: #fcfcfc;
        }
        .img-doc {
            width: 50px;
            height: 50px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #ddd;
        }
        .signature {
            margin-top: 35px;
            width: 100%;
        }
        .signature td {
            border: none;
            text-align: center;
            width: 50%;
        }
        .footer {
            margin-top: 40px;
            text-align: center;
            font-size: 9px;
            color: #aaa;
            border-top: 1px solid #eee;
            padding-top: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">GROSIR TIGA BERSAUDARA</div>
        <div class="subtitle">Jl. Rimbo Data, Bandar Buat, Padang</div>
        <div class="subtitle">Telp: 082181769006</div>
        <div class="subtitle">Laporan Resmi Data Kerusakan Produk Supplier</div>
    </div>

    <div class="report-info">
        <table style="width: 100%">
            <tr>
                <td class="label">No. Laporan:</td>
                <td>{{ $report_number }}</td>
                <td class="label" style="text-align: right;">Tanggal Cetak:</td>
                <td style="text-align: right;">{{ \Carbon\Carbon::parse($date)->format('d/m/Y H:i') }}</td>
            </tr>
        </table>
    </div>

    <div class="supplier-info">
        <table style="width: 100%">
            <tr>
                <td class="label" style="width: 80px">Supplier:</td>
                <td><strong>{{ $supplier->name }}</strong></td>
                <td class="label" style="width: 50px">Telepon:</td>
                <td>{{ $supplier->phone ?? '-' }}</td>
            </tr>
            <tr>
                <td class="label">Alamat:</td>
                <td colspan="3">{{ $supplier->address ?? '-' }}</td>
            </tr>
        </table>
    </div>

    <div class="summary-box">
        <table class="summary-table">
            <tr>
                <td>
                    <div class="title-sm">Total Jenis Produk</div>
                    <div class="value">{{ $summary['total_items'] }} Item</div>
                </td>
                <td>
                    <div class="title-sm">Total Kuantitas Rusak</div>
                    <div class="value">{{ $summary['total_quantity'] }}</div>
                </td>
                <td>
                    <div class="title-sm">Total Nilai Kerugian</div>
                    <div class="value">Rp {{ number_format($summary['total_loss'], 0, ',', '.') }}</div>
                </td>
            </tr>
        </table>
    </div>

    <h4 style="margin-top: 10px; color: #333;">Rincian Item Barang Rusak:</h4>

    <table class="data-table">
        <thead>
            <tr>
                <th style="width: 5%;">No</th>
                <th style="width: 12%;">Tanggal</th>
                <th style="width: 25%;">Nama Produk</th>
                <th style="width: 15%;">Jumlah Rusak</th>
                <th style="width: 15%;">Kerugian (Rp)</th>
                <th style="width: 18%;">Penyebab Kerusakan</th>
                <th style="width: 10%;">Bukti</th>
            </tr>
        </thead>
        <tbody>
            @foreach($badProducts as $index => $item)
            <tr>
                <td style="text-align: center;">{{ $index + 1 }}</td>
                <td>{{ \Carbon\Carbon::parse($item->incident_date)->format('d/m/Y') }}</td>
                <td><strong>{{ $item->product->name ?? '-' }}</strong></td>
                <td>{{ $item->quantity }} {{ $item->unit }}</td>
                <td>{{ number_format($item->loss_amount, 0, ',', '.') }}</td>
                <td>{{ $item->damage_reason }}</td>
                <td style="text-align: center;">
                    @php
                        $imageBase64 = null;
                        $mimeType = 'image/jpeg';
                        
                        // Gunakan image_url (accessor) yang sudah ada di model BadProduct
                        $imageUrl = $item->image_url;
                        
                        if (!empty($imageUrl)) {
                            // Deteksi MIME type dari ekstensi URL
                            $lowerUrl = strtolower($imageUrl);
                            if (str_ends_with($lowerUrl, '.png')) {
                                $mimeType = 'image/png';
                            } elseif (str_ends_with($lowerUrl, '.webp')) {
                                $mimeType = 'image/webp';
                            }
                            
                            try {
                                $imageData = @file_get_contents($imageUrl);
                                if ($imageData !== false) {
                                    $imageBase64 = base64_encode($imageData);
                                }
                            } catch (\Exception $e) {
                                $imageBase64 = null;
                            }
                        }
                    @endphp
                    
                    @if($imageBase64)
                        <img src="data:{{ $mimeType }};base64,{{ $imageBase64 }}" class="img-doc">
                    @else
                        <span style="color: #999; font-size: 9px;">Tidak ada</span>
                    @endif
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <table class="signature">
        <tr>
            <td>
                <p>Mengetahui,</p>
                <p style="font-weight: bold; margin-top: 5px;">Owner Grosir</p>
                <br><br><br>
                <p>( ___________________ )</p>
            </td>
            <td>
                <p>Padang, {{ date('d M Y') }}</p>
                <p style="font-weight: bold; margin-top: 5px;">Administrator</p>
                <br><br><br>
                <p>( ___________________ )</p>
            </td>
        </tr>
    </table>

    <div class="footer">
        Data laporan ini telah diarsipkan dan dihapus dari sistem antrian aktif setelah sukses diunduh.<br>
        Grosir Tiga Bersaudara &copy; 2026
    </div>
</body>
</html>