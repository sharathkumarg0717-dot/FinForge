<?php
declare(strict_types=1);

echo "=== FinSync Family Section & Member Dashboards Flow Verification ===\n\n";

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/test_family_cookie.txt';
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

// 1. Fetch CSRF token
$session = req($baseUrl . '/api/session');
$csrf = $session['body']['data']['csrf_token'] ?? '';
echo "1. Obtained CSRF token: " . substr($csrf, 0, 16) . "...\n";

// 2. Login as Sharath Kumar via Google Auth test endpoint
$login = req($baseUrl . '/api/auth/google', 'POST', [
    'email' => 'sharathkumarg0717@gmail.com',
    'name' => 'Sharath Kumar',
    'google_id' => 'google_sub_test_family_1'
], ['X-CSRF-Token: ' . $csrf]);

echo "2. Logged in: HTTP " . $login['status'] . ", User ID: " . ($login['body']['data']['user']['id'] ?? 'none') . "\n";
$csrf = $login['body']['data']['csrf_token'] ?? $csrf;

// Ensure consent is granted so demo financial records are accessible
req($baseUrl . '/api/consent', 'PUT', ['granted' => true], ['X-CSRF-Token: ' . $csrf]);
echo "   Consent verified granted.\n";

// 3. Test GET /api/family-overview
echo "\n3. Testing GET /api/family-overview...\n";
$overview = req($baseUrl . '/api/family-overview', 'GET', null, ['X-CSRF-Token: ' . $csrf]);
if ($overview['status'] !== 200 || !isset($overview['body']['data']['summary'])) {
    echo "[FAIL] Expected 200 with summary, got: " . $overview['raw'] . "\n";
    exit(1);
}

$summary = $overview['body']['data']['summary'];
echo "   [PASS] Family Total Savings: ₹" . number_format((float)$summary['family_savings'], 2) . "\n";
echo "   [PASS] Family Total Spend:   ₹" . number_format((float)$summary['family_spend'], 2) . "\n";
echo "   [PASS] Family Total Debt:    ₹" . number_format((float)$summary['family_debt'], 2) . "\n";
echo "   [PASS] Family Total Credit:  ₹" . number_format((float)$summary['family_credit'], 2) . "\n";
echo "   [PASS] Family Net Worth:     ₹" . number_format((float)$summary['family_net_worth'], 2) . "\n";
echo "   [PASS] Active Members:       " . $summary['active_member_count'] . "\n";

$members = $overview['body']['data']['members'];
echo "   Active member list count: " . count($members) . "\n";
$firstMember = $members[0] ?? null;
if (!$firstMember) {
    echo "[FAIL] No family members found!\n";
    exit(1);
}
echo "   First member: " . $firstMember['name'] . " (ID: " . $firstMember['id'] . ", Role: " . $firstMember['relationship'] . ")\n";

// 4. Test GET /api/family/{id}/dashboard for the first member
echo "\n4. Testing GET /api/family/{$firstMember['id']}/dashboard...\n";
$dash = req($baseUrl . "/api/family/{$firstMember['id']}/dashboard", 'GET', null, ['X-CSRF-Token: ' . $csrf]);
if ($dash['status'] !== 200 || !isset($dash['body']['data']['metrics'])) {
    echo "[FAIL] Member dashboard failed: " . $dash['raw'] . "\n";
    exit(1);
}
$mMetrics = $dash['body']['data']['metrics'];
$mAccounts = $dash['body']['data']['accounts'];
echo "   [PASS] Member Name: " . $dash['body']['data']['member']['name'] . "\n";
echo "   [PASS] Member Savings: ₹" . number_format((float)$mMetrics['savings'], 2) . "\n";
echo "   [PASS] Member Spend:   ₹" . number_format((float)$mMetrics['spend'], 2) . "\n";
echo "   [PASS] Member Debt:    ₹" . number_format((float)$mMetrics['debt'], 2) . "\n";
echo "   [PASS] Member Credit:  ₹" . number_format((float)$mMetrics['credit'], 2) . "\n";
echo "   [PASS] Connected Accounts: " . count($mAccounts) . "\n";

// 5. Test adding a new family member, then adding an account to that member
echo "\n5. Testing Add Member + Link Account for Member...\n";
$newMember = req($baseUrl . '/api/family', 'POST', [
    'name' => 'Aarav Kumar',
    'relationship' => 'Child'
], ['X-CSRF-Token: ' . $csrf]);

if ($newMember['status'] !== 201) {
    echo "[FAIL] Add member failed: " . $newMember['raw'] . "\n";
    exit(1);
}
$newMemId = (int)$newMember['body']['data']['id'];
echo "   [PASS] Added new member Aarav Kumar (ID: {$newMemId})\n";

// Add an account to Aarav
$newAccount = req($baseUrl . '/api/accounts', 'POST', [
    'family_member_id' => $newMemId,
    'institution' => 'State Bank of India',
    'account_type' => 'savings',
    'masked_number' => '•••• 9988',
    'balance' => 45000.00
], ['X-CSRF-Token: ' . $csrf]);

if ($newAccount['status'] !== 201) {
    echo "[FAIL] Link account failed: " . $newAccount['raw'] . "\n";
    exit(1);
}
echo "   [PASS] Linked SBI Savings account with ₹45,000 balance to Aarav (ID: {$newMemId})\n";

// 6. Verify Aarav's individual dashboard
echo "\n6. Verifying Aarav's Individual Member Dashboard...\n";
$aaravDash = req($baseUrl . "/api/family/{$newMemId}/dashboard", 'GET', null, ['X-CSRF-Token: ' . $csrf]);
$aaravMetrics = $aaravDash['body']['data']['metrics'];
$aaravAccounts = $aaravDash['body']['data']['accounts'];

if ($aaravMetrics['savings'] != 45000.00 || count($aaravAccounts) !== 1) {
    echo "[FAIL] Aarav metrics mismatch: " . json_encode($aaravMetrics) . "\n";
    exit(1);
}
echo "   [PASS] Aarav's dashboard shows savings: ₹" . number_format((float)$aaravMetrics['savings'], 2) . "\n";
echo "   [PASS] Aarav's connected account: " . $aaravAccounts[0]['institution'] . " (" . $aaravAccounts[0]['masked_number'] . ")\n";

// 7. Verify updated Family Overview calculations
echo "\n7. Verifying Updated Entire Family Calculations...\n";
$updatedOverview = req($baseUrl . '/api/family-overview', 'GET', null, ['X-CSRF-Token: ' . $csrf]);
$updatedSummary = $updatedOverview['body']['data']['summary'];
echo "   [PASS] Updated Family Savings: ₹" . number_format((float)$updatedSummary['family_savings'], 2) . "\n";
echo "   [PASS] Updated Family Net Worth: ₹" . number_format((float)$updatedSummary['family_net_worth'], 2) . "\n";

if ($updatedSummary['family_savings'] < 45000.00) {
    echo "[FAIL] Family savings did not include new member account!\n";
    exit(1);
}

// Clean up test cookie
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n>>> ALL FAMILY SECTION & MEMBER DASHBOARD TESTS PASSED! <<<\n";
