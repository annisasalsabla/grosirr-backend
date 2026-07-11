<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kode Verifikasi</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .container {
            background-color: #f9f9f9;
            border-radius: 8px;
            padding: 30px;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 {
            color: #2563eb;
            margin: 0;
        }
        .otp-code {
            font-size: 32px;
            font-weight: bold;
            text-align: center;
            letter-spacing: 8px;
            color: #2563eb;
            padding: 20px;
            background: #ffffff;
            border-radius: 8px;
            margin: 20px 0;
        }
        .footer {
            margin-top: 30px;
            font-size: 12px;
            color: #666;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Kode Verifikasi</h1>
        </div>

        <p>Assalamu'alaikum,</p>

        <p>Berikut adalah kode verifikasi Anda:</p>

        <div class="otp-code">{{ $otp }}</div>

        <p><strong>Catatan:</strong></p>
        <ul>
            <li>Kode ini berlaku selama 5 menit</li>
            <li>Jangan bagikan kode ini kepada siapa pun</li>
        </ul>

        <div class="footer">
            <p>&copy; {{ date('Y') }} Grosir Tiga Bersaudara. All rights reserved.</p>
        </div>
    </div>
</body>
</html>