<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "1. Raw Query dari Transaction Details:\n";
$details = DB::table('transaction_details')
    ->join('transactions', 'transactions.id', '=', 'transaction_details.transaction_id')
    ->join('products', 'products.id', '=', 'transaction_details.product_id')
    ->whereMonth('transactions.created_at', now()->month)
    ->whereYear('transactions.created_at', now()->year)
    ->whereIn('transactions.payment_status', ['paid', 'partial', 'unpaid', 'pending'])
    ->select('products.category', DB::raw('SUM(transaction_details.quantity) as total_qty'))
    ->groupBy('products.category')
    ->get();
foreach($details as $d) echo $d->category . ': ' . $d->total_qty . "\n";

echo "\n2. Raw Query dari tabel profits:\n";
$profits = DB::table('profits')
    ->join('products', 'products.id', '=', 'profits.product_id')
    ->whereMonth('profits.profit_date', now()->month)
    ->whereYear('profits.profit_date', now()->year)
    ->select('products.category', DB::raw('SUM(profits.quantity_sold) as total_qty'))
    ->groupBy('products.category')
    ->get();
foreach($profits as $p) echo $p->category . ': ' . $p->total_qty . "\n";
