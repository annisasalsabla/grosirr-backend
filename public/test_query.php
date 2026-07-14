<?php
require __DIR__."/../vendor/autoload.php";
$app = require_once __DIR__."/../bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Transaction;

$today = now("Asia/Jakarta")->toDateString();
$txs = Transaction::whereDate("created_at", $today)->orderBy("created_at", "desc")->get();

echo "USING LARAVEL WHERE DATE: " . $today . "\n";
echo "ID | METHOD | STATUS | AMOUNT | CREATED_AT | TX_DATE\n";
echo str_repeat("-", 80) . "\n";
foreach($txs as $t) {
    echo $t->id . " | " . $t->payment_method . " | " . $t->payment_status . " | " . $t->total_amount . " | " . $t->created_at . " | " . $t->tx_date . "\n";
}

$all = Transaction::orderBy("created_at", "desc")->take(20)->get();
echo "\nLAST 20 TRANSACTIONS IN DB (ANY DATE):\n";
echo str_repeat("-", 80) . "\n";
foreach($all as $t) {
    echo $t->id . " | " . $t->payment_method . " | " . $t->payment_status . " | " . $t->total_amount . " | " . $t->created_at . " | " . $t->tx_date . "\n";
}

