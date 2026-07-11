<?php

use Illuminate\Support\Facades\Schedule;

// Jadwal Otomatis:
// - Notifikasi piutang jatuh tempo: Setiap hari jam 08:00
// - Cek stok menipis: Setiap hari jam 07:00 dan 17:00
Schedule::command('receivable:send-daily-notification')->dailyAt('08:00');
Schedule::command('stock:check-low --force')->dailyAt('07:00');
Schedule::command('stock:check-low --force')->dailyAt('17:00');

// - Check Admin Notifications (hutang, piutang, stok): Setiap 15 menit
Schedule::command('app:check-admin-notifications')
        ->everyFifteenMinutes()
        ->appendOutputTo(storage_path('logs/scheduler-notif.log'));