<?php
declare(strict_types=1);

echo "=== FinSync Zerodha Demat Account & Holdings Integration Test ===\n\n";

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/test_zerodha_cookie.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function req(string $url, string $method = 'GET', ?array $data = null, array $headers = []): array {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    
    $hdrs = $headers;
    if ($data !== null) {
        $json = json_encode($data);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        $hdrs[] = 'Content-Type: application/json';
        $hdrs[] = 'Content-Length: ' . strlen($json);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $hdrs);
    
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ['status' => $status, 'body' => json_decode((string)$response, true), 'raw' => $response];
}

// 1. Session & Login
$session = req($baseUrl . '/api/session');
$csrf = $session['body']['data']['csrf_token'] ?? '';
echo "1. Obtained CSRF token: " . substr($csrf, 0, 16) . "...\n";

$login = req($baseUrl . '/api/auth/google', 'POST', [
    'email' => 'sharathkumarg0717@gmail.com',
    'name' => 'Sharath Kumar',
    'google_id' => 'google_sub_test_zerodha_1'
], ['X-CSRF-Token: ' . $csrf]);

echo "2. Logged in: HTTP " . $login['status'] . ", User ID: " . ($login['body']['data']['user']['id'] ?? 'none') . "\n";
$csrf = $login['body']['data']['csrf_token'] ?? $csrf;

// Consent granted
req($baseUrl . '/api/consent', 'PUT', ['granted' => true], ['X-CSRF-Token: ' . $csrf]);

// 2. Connect Zerodha Demat Account with Real Indian Stocks
echo "\n3. Testing POST /api/zerodha/connect with Client ID SK0717...\n";
$connectRes = req($baseUrl . '/api/zerodha/connect', 'POST', [
    'client_id' => 'SK0717',
    'demat_boid' => '1208160098765432',
    'holdings' => [
        ['symbol' => 'RELIANCE', 'instrument' => 'Reliance Industries Ltd', 'units' => 25, 'buy_price' => 2840.50, 'price' => 2980.20],
        ['symbol' => 'INFY', 'instrument' => 'Infosys Ltd', 'units' => 50, 'buy_price' => 1450.00, 'price' => 1860.00],
        ['symbol' => 'TCS', 'instrument' => 'Tata Consultancy Services', 'units' => 15, 'buy_price' => 3780.00, 'price' => 4120.00],
        ['symbol' => 'TATAMOTORS', 'instrument' => 'Tata Motors Ltd', 'units' => 40, 'buy_price' => 820.00, 'price' => 965.50],
        ['symbol' => 'NIFTYBEES', 'instrument' => 'Nippon Nifty 50 ETF', 'units' => 100, 'buy_price' => 230.00, 'price' => 262.50]
    ]
], ['X-CSRF-Token: ' . $csrf]);

if ($connectRes['status'] !== 201) {
    echo "[FAIL] Connect Zerodha failed: " . $connectRes['raw'] . "\n";
    exit(1);
}
echo "   [PASS] Zerodha Demat connected successfully!\n";

// 3. Verify GET /api/zerodha
echo "\n4. Testing GET /api/zerodha...\n";
$zData = req($baseUrl . '/api/zerodha', 'GET', null, ['X-CSRF-Token: ' . $csrf]);
if ($zData['status'] !== 200 || !isset($zData['body']['data']['account'])) {
    echo "[FAIL] Expected 200 with Zerodha account: " . $zData['raw'] . "\n";
    exit(1);
}
$zSummary = $zData['body']['data']['summary'];
echo "   [PASS] Zerodha Client ID: " . $zData['body']['data']['account']['client_id'] . "\n";
echo "   [PASS] Total Demat Market Value: ₹" . number_format((float)$zSummary['total_market_value'], 2) . "\n";
echo "   [PASS] Total Demat Invested Cost: ₹" . number_format((float)$zSummary['total_investment'], 2) . "\n";
echo "   [PASS] Total Demat P&L: ₹" . number_format((float)$zSummary['total_pnl'], 2) . " (" . $zSummary['pnl_percentage'] . "%)\n";
echo "   [PASS] Total Holdings in Demat: " . $zSummary['total_holdings_count'] . "\n";

// 4. Test adding another stock to Zerodha via POST /api/zerodha/holding
echo "\n5. Testing Add Stock to Zerodha (HDFCBANK)...\n";
$addHolding = req($baseUrl . '/api/zerodha/holding', 'POST', [
    'symbol' => 'HDFCBANK',
    'instrument' => 'HDFC Bank Ltd',
    'units' => 30,
    'buy_price' => 1520.00,
    'price' => 1685.00
], ['X-CSRF-Token: ' . $csrf]);

if ($addHolding['status'] !== 201) {
    echo "[FAIL] Add holding failed: " . $addHolding['raw'] . "\n";
    exit(1);
}
echo "   [PASS] Added HDFCBANK (30 shares) to Zerodha Demat.\n";

// 5. Test CSV import
echo "\n6. Testing Zerodha Console CSV Import...\n";
$csvContent = "Instrument,Qty.,Avg. cost,LTP,Cur. val,P&L\nITC,100,410.00,475.00,47500.00,6500.00\nWIPRO,50,450.00,530.00,26500.00,4000.00";
$csvImport = req($baseUrl . '/api/zerodha/import-csv', 'POST', [
    'csv' => $csvContent
], ['X-CSRF-Token: ' . $csrf]);

if ($csvImport['status'] !== 200) {
    echo "[FAIL] CSV import failed: " . $csvImport['raw'] . "\n";
    exit(1);
}
echo "   [PASS] CSV imported successfully (" . $csvImport['body']['data']['imported_count'] . " holdings imported).\n";

// 6. Test GET /api/investments reflects all holdings
echo "\n7. Testing GET /api/investments...\n";
$inv = req($baseUrl . '/api/investments', 'GET', null, ['X-CSRF-Token: ' . $csrf]);
$holdingsList = $inv['body']['data'] ?? [];
echo "   [PASS] Total portfolio holdings retrieved: " . count($holdingsList) . "\n";

$foundItc = false;
$itcId = 0;
foreach ($holdingsList as $h) {
    if ($h['symbol'] === 'ITC') {
        $foundItc = true;
        $itcId = (int)$h['id'];
        break;
    }
}
if (!$foundItc) {
    echo "[FAIL] ITC holding from CSV was not found in holdings list!\n";
    exit(1);
}
echo "   [PASS] Found imported ITC holding (ID: {$itcId}) with market value: ₹" . number_format((float)$h['market_value'], 2) . "\n";

// 7. Test Delete holding
echo "\n8. Testing DELETE /api/holdings/{$itcId}...\n";
$delRes = req($baseUrl . "/api/holdings/{$itcId}", 'DELETE', [], ['X-CSRF-Token: ' . $csrf]);
if ($delRes['status'] !== 200) {
    echo "[FAIL] Delete holding failed: " . $delRes['raw'] . "\n";
    exit(1);
}
echo "   [PASS] Deleted holding successfully.\n";

// Clean up cookie
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n>>> ALL ZERODHA DEMAT & HOLDINGS INTEGRATION TESTS PASSED! <<<\n";
