<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

function fetchJson($kernel, $url) {
    $request = Illuminate\Http\Request::create($url, 'GET');
    $response = $kernel->handle($request);
    return json_decode($response->getContent(), true);
}

// Mock auth by overriding middleware or using controller directly?
// Since Auth is required, let's just use the controller directly like before, or we can use curl.
// But we saw `curl` was failing due to jq. We can just use curl and format it with php!
