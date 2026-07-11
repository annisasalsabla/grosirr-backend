<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$query = App\Models\Transaction::query();
$countBefore = $query->count();
$sumBefore = $query->sum('total_amount');

$paginated = $query->paginate(10);
$countAfter = $query->count(); // count() drops limit/offset
$sumAfter = $query->sum('total_amount'); // sum() drops limit/offset too?
$pluckAfter = $query->pluck('id');

echo "Count Before: $countBefore\n";
echo "Sum Before: $sumBefore\n";
echo "Count After: $countAfter\n";
echo "Sum After: $sumAfter\n";
echo "Pluck count After: " . $pluckAfter->count() . "\n";
