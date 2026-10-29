<?php
declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = tempnam(sys_get_temp_dir(), 'finsync_google_cookie_');

function curlRequest(string $url, string $method = 'GET', ?array $data = null, array $headers = []): array {
    global $cookieFile;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HEADER, true);

    $defaultHeaders = ['Accept: application/json'];
    if ($data !== null) {
        $body = json_encode($data, JSON_THROW_ON_ERROR);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $defaultHeaders[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));

    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $rawHeader = substr($response, 0, $headerSize);
    $rawBody = substr($response, $headerSize);
    $json = json_decode($rawBody, true);

    return [
        'code' => $httpCode,
        'header' => $rawHeader,
        'body' => $json,
        'raw_body' => $rawBody
    ];
}

echo "=== FinSync Google Authentication & Currency (INR) Verification ===\n";

// 1. Get Session & CSRF
echo "\n1. Fetching session...\n";
$res = curlRequest("$baseUrl/api/session");
if ($res['code'] !== 200 || !isset($res['body']['data']['csrf_token'])) {
    throw new RuntimeException("Failed to get session. Code: {$res['code']}");
}
$csrf = $res['body']['data']['csrf_token'];
echo "   CSRF token: $csrf\n";

// 2. Test Google Auth with user's Google credentials
$googleEmail = 'sharathkumarg0717@gmail.com';
$googleName = 'Sharath Kumar';
echo "\n2. Testing Google Authentication for $googleEmail ($googleName)...\n";
$res = curlRequest("$baseUrl/api/auth/google", 'POST', [
    'email' => $googleEmail,
    'name' => $googleName,
    'google_id' => 'google_sub_test_' . time(),
    'avatar_url' => 'https://lh3.googleusercontent.com/a/default-user',
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 200 && $res['code'] !== 201) {
    throw new RuntimeException("Google auth failed with code {$res['code']}: {$res['raw_body']}");
}
$user = $res['body']['data']['user'];
$userId = (int)$user['id'];
$csrf = $res['body']['data']['csrf_token'];
echo "   Google auth succeeded! User ID: $userId, Name: {$user['name']}, Email: {$user['email']}\n";

// 3. Verify in MySQL Database
echo "\n3. Verifying user in MySQL database directly...\n";
require_once dirname(__DIR__) . '/app/Core/Database.php';
$pdo = \FinSync\Core\Database::connection();
$stmt = $pdo->prepare('SELECT id, name, email, google_id, auth_provider, status FROM users WHERE id = :id');
$stmt->execute(['id' => $userId]);
$dbUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dbUser) {
    throw new RuntimeException("User not found in MySQL users table!");
}
echo "   Found in MySQL: ID={$dbUser['id']}, Email={$dbUser['email']}, Provider={$dbUser['auth_provider']}, GoogleID={$dbUser['google_id']}\n";
if ($dbUser['auth_provider'] !== 'google') {
    throw new RuntimeException("Expected auth_provider 'google', got: '{$dbUser['auth_provider']}'");
}

// 4. Test repeated Google Login (existing user login flow)
echo "\n4. Testing repeated Google Login for existing account...\n";
$res = curlRequest("$baseUrl/api/auth/google", 'POST', [
    'email' => $googleEmail,
    'name' => $googleName,
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 200) {
    throw new RuntimeException("Existing Google login failed with code {$res['code']}: {$res['raw_body']}");
}
$existingId = (int)$res['body']['data']['user']['id'];
$csrf = $res['body']['data']['csrf_token'];
if ($existingId !== $userId) {
    throw new RuntimeException("Expected same User ID $userId, got $existingId");
}
echo "   Existing user successfully logged in with matched User ID $userId.\n";

// 5. Test Google Auth validation (invalid email)
echo "\n5. Testing validation on invalid email...\n";
$res = curlRequest("$baseUrl/api/auth/google", 'POST', [
    'email' => 'invalid-email-string',
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 400 && $res['code'] !== 422) {
    throw new RuntimeException("Expected HTTP 400/422 for invalid email, got {$res['code']}");
}
echo "   Validation correctly rejected invalid email with status {$res['code']}.\n";

// 6. Test Currency formatting in Financial Analysis (INR / ₹)
echo "\n6. Testing Indian Rupee (₹) in Financial Analysis...\n";
require_once dirname(__DIR__) . '/app/Models/FinanceRepository.php';
$repo = new \FinSync\Models\FinanceRepository($pdo);
$record = [
    'record_month' => date('Y-m-01'),
    'monthly_income' => '85000.00',
    'other_income' => '15000.00',
    'monthly_expenses' => '45000.00',
    'expense_categories' => [
        ['category' => 'Rent', 'amount' => '25000.00'],
        ['category' => 'Food', 'amount' => '12000.00'],
        ['category' => 'Utilities', 'amount' => '8000.00'],
    ],
    'savings' => '200000.00',
    'investments' => '500000.00',
    'loans_debt' => '0.00',
    'credit_card_balance' => '0.00',
    'financial_goals' => 'Build emergency fund of ₹5,00,000',
    'notes' => 'INR test record',
];
$recId = $repo->savePersonalFinance($userId, $record);
echo "   Saved finance record ID: $recId\n";

$saved = $repo->personalFinance($userId, substr($record['record_month'], 0, 7));
$analysis = $saved['analysis'];

// Check that indicators and suggestions use ₹ and not $
$hasRupee = false;
$hasDollar = false;
foreach ($analysis['indicators'] as $ind) {
    if (strpos($ind['label'], '₹') !== false) $hasRupee = true;
    if (strpos($ind['label'], '$') !== false) $hasDollar = true;
}
foreach ($analysis['suggestions'] as $sug) {
    if (strpos($sug, '₹') !== false) $hasRupee = true;
    if (strpos($sug, '$') !== false) $hasDollar = true;
}

if (!$hasRupee) {
    throw new RuntimeException("Rupee symbol (₹) not found in analysis output!");
}
if ($hasDollar) {
    throw new RuntimeException("Found unexpected dollar symbol ($) in analysis output!");
}
echo "   [PASS] Analysis successfully outputted Indian Rupee (₹) with zero dollar symbols.\n";

// 7. Verify index.php form labels contain ₹
echo "\n7. Verifying index.php form labels and cards have (₹)...\n";
$html = file_get_contents(dirname(__DIR__) . '/public/index.php');
if (strpos($html, 'Monthly income (₹)') === false) {
    throw new RuntimeException("Monthly income (₹) label not found in index.php!");
}
if (strpos($html, 'Monthly expenses (₹)') === false) {
    throw new RuntimeException("Monthly expenses (₹) label not found in index.php!");
}
if (strpos($html, 'id="net-worth">₹—') === false) {
    throw new RuntimeException("Rupee net worth placeholder not found in index.php!");
}
if (strpos($html, 'Sign in with Google') === false) {
    throw new RuntimeException("Sign in with Google button not found in index.php!");
}
echo "   [PASS] HTML form labels and cards correctly use ₹ and Google Sign-in button is present.\n";

// 8. Verify app.js money function uses INR
echo "\n8. Verifying app.js uses currency 'INR'...\n";
$js = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');
if (strpos($js, "currency: 'INR'") === false) {
    throw new RuntimeException("app.js money function does not specify currency: 'INR'!");
}
if (strpos($js, "en-IN") === false) {
    throw new RuntimeException("app.js money function does not specify locale 'en-IN'!");
}
if (strpos($js, "api('/api/auth/google'") === false) {
    throw new RuntimeException("app.js missing Google auth API call!");
}
echo "   [PASS] JavaScript correctly uses en-IN, INR, and Google auth handler.\n";

// Clean up
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n>>> ALL GOOGLE AUTH & INDIAN RUPEE (INR) TESTS PASSED! <<<\n";
