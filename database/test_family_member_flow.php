<?php
declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = tempnam(sys_get_temp_dir(), 'finsync_cookie_');

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

echo "=== FinSync Family Member Flow Test ===\n";

// 1. Get Session & CSRF
echo "\n1. Fetching session...\n";
$res = curlRequest("$baseUrl/api/session");
if ($res['code'] !== 200 || !isset($res['body']['data']['csrf_token'])) {
    throw new RuntimeException("Failed to get session. Code: {$res['code']}, Body: {$res['raw_body']}");
}
$csrf = $res['body']['data']['csrf_token'];
echo "   CSRF token obtained.\n";

// 2. Register/Login test user
$testEmail = 'family_tester_' . time() . '_' . bin2hex(random_bytes(4)) . '@example.com';
$password = 'Secret123!';
echo "\n2. Registering test user: $testEmail...\n";
$res = curlRequest("$baseUrl/api/register", 'POST', [
    'name' => 'Family Tester',
    'email' => $testEmail,
    'password' => $password,
    'confirm_password' => $password,
    'consent_demo' => true
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 201) {
    throw new RuntimeException("Registration failed: {$res['raw_body']}");
}
$csrf = $res['body']['data']['csrf_token'];
$userId = $res['body']['data']['user']['id'];
echo "   Registered user ID: $userId\n";

// 3. Grant consent
echo "\n3. Granting consent...\n";
$res = curlRequest("$baseUrl/api/consent", 'PUT', [
    'granted' => true
], ["X-CSRF-Token: $csrf"]);
if ($res['code'] !== 200) {
    throw new RuntimeException("Consent failed: {$res['raw_body']}");
}
echo "   Consent granted successfully.\n";

// 4. Initial family list
echo "\n4. Fetching initial family list...\n";
$res = curlRequest("$baseUrl/api/family", 'GET', null, ["X-CSRF-Token: $csrf"]);
if ($res['code'] !== 200) {
    throw new RuntimeException("Fetch family failed: {$res['raw_body']}");
}
$initialCount = count($res['body']['data'] ?? []);
echo "   Initial family member count: $initialCount\n";

// 5. Add a valid family member
$memberName = 'Sarah Connor';
$memberRel = 'Partner';
echo "\n5. Adding valid family member: $memberName ($memberRel)...\n";
$res = curlRequest("$baseUrl/api/family", 'POST', [
    'name' => $memberName,
    'relationship' => $memberRel
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 201) {
    throw new RuntimeException("Add family member failed with code {$res['code']}: {$res['raw_body']}");
}
$newMember = $res['body']['data'];
echo "   Added successfully: ID {$newMember['id']}, Name: {$newMember['name']}, Rel: {$newMember['relationship']}\n";

// 6. Fetch family list again and verify presence immediately
echo "\n6. Verifying family list reflects the new member immediately...\n";
$res = curlRequest("$baseUrl/api/family", 'GET', null, ["X-CSRF-Token: $csrf"]);
if ($res['code'] !== 200) {
    throw new RuntimeException("Fetch family failed: {$res['raw_body']}");
}
$familyList = $res['body']['data'] ?? [];
$found = false;
foreach ($familyList as $member) {
    if ((int)$member['id'] === (int)$newMember['id'] && $member['name'] === $memberName) {
        $found = true;
        break;
    }
}
if (!$found) {
    throw new RuntimeException("New member not found in GET /api/family response!");
}
echo "   Confirmed: $memberName is present in the active family list.\n";

// 7. Verify in MySQL database directly
echo "\n7. Verifying in MySQL database directly...\n";
require_once dirname(__DIR__) . '/app/Core/Database.php';
$pdo = \FinSync\Core\Database::connection();
$stmt = $pdo->prepare('SELECT * FROM family_members WHERE id = :id AND user_id = :user_id');
$stmt->execute(['id' => $newMember['id'], 'user_id' => $userId]);
$dbRecord = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$dbRecord) {
    throw new RuntimeException("Record not found in MySQL family_members table!");
}
echo "   Confirmed in MySQL: ID={$dbRecord['id']}, Name={$dbRecord['name']}, Relationship={$dbRecord['relationship']}, Status={$dbRecord['status']}\n";

// 8. Test validation failure (empty / invalid name)
echo "\n8. Testing validation failure...\n";
$res = curlRequest("$baseUrl/api/family", 'POST', [
    'name' => 'X', // too short (< 2 chars)
    'relationship' => 'Partner'
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 400 && $res['code'] !== 422) {
    throw new RuntimeException("Expected HTTP 400/422 for invalid name, got {$res['code']}: {$res['raw_body']}");
}
echo "   Validation correctly rejected with status {$res['code']}: {$res['body']['error']['message']}\n";

// 9. Inspect JavaScript source for proper form handling & zero references to null reset
echo "\n9. Inspecting public/assets/app.js for syntax and null reference prevention...\n";
$jsCode = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');

// Verify that event.currentTarget.reset() does NOT exist anywhere in app.js
if (strpos($jsCode, 'event.currentTarget.reset()') !== false) {
    throw new RuntimeException("Found dangerous event.currentTarget.reset() in app.js!");
}
echo "   Verified: No event.currentTarget.reset() calls exist.\n";

// Verify that form.reset is checked and called safely
if (strpos($jsCode, 'form.reset()') === false) {
    throw new RuntimeException("form.reset() call missing in app.js!");
}
echo "   Verified: form.reset() is called properly on a captured element reference.\n";

// Verify submit handler captures form synchronously
if (!preg_match("/\\('#family-form'\\)\\.addEventListener\\('submit',\\s*async\\s*\\(event\\)\\s*=>\\s*\\{\\s*event\\.preventDefault\\(\\);\\s*const form = event\\.currentTarget/", $jsCode)) {
    throw new RuntimeException("family-form submit listener does not synchronously capture form!");
}
echo "   Verified: Synchronous form reference capture is present.\n";

// Clean up cookie file
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n>>> ALL FAMILY MEMBER TESTS PASSED SUCCESSFULLY! <<<\n";
