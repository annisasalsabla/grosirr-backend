<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
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
        .content {
            margin: 30px 0;
        }
        .button {
            display: inline-block;
            background-color: #2563eb;
            color: #ffffff;
            padding: 12px 30px;
            text-decoration: none;
            border-radius: 6px;
            font-weight: bold;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #1d4ed8;
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
            <h1>Reset Password</h1>
        </div>

        <div class="content">
            <p>Assalamu'alaikum,</p>

            <p>Kami menerima permintaan reset password untuk akun Anda. Klik tombol di bawah ini untuk mereset password Anda:</p>

            <div style="text-align: center;">
                <a href="{{ config('app.frontend_url', 'https://grosirtiga.my.id') }}/reset-password/{{ $token }}?email={{ urlencode($email) }}" class="button">
                    Reset Password
                </a>
            </div>

            <p>Atau Anda dapat menyalin dan membuka tautan berikut:</p>
            <p style="word-break: break-all; font-size: 14px; color: #2563eb;">
                {{ config('app.frontend_url', 'https://grosirtiga.my.id') }}/reset-password/{{ $token }}?email={{ urlencode($email) }}
            </p>

            <p><strong>Catatan:</strong></p>
            <ul>
                <li>Link reset password ini akan berlaku selama 1 jam</li>
                <li>Jika Anda tidak meminta reset password, abaikan email ini</li>
            </ul>
        </div>

        <div class="footer">
            <p>&copy; {{ date('Y') }} Grosir Tiga Bersaudara. All rights reserved.</p>
        </div>
    </div>
</body>
</html>