<?php
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Kita bisa mensimulasikan HTTP Request ke aplikasi
function testEndpoint($method, $uri) {
    echo "\n========== TEST ROUTE: $method $uri ==========\n";
    $request = Request::create($uri, $method);
    
    // Bypass authentication untuk testing route matching (supaya kita langsung lihat error Controller atau sukses)
    // Karena kita tidak punya token admin di script ini, kita cukup cek apakah route-nya MATCH dan memanggil fungsi yang benar.
    $route = app('router')->getRoutes()->match($request);
    echo "Matched Action: " . $route->getActionName() . "\n";
}

try {
    testEndpoint('GET', '/api/admin/customers/member');
    testEndpoint('GET', '/api/admin/customers/calon-member');
    testEndpoint('GET', '/api/admin/customers/1'); // {id}
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
