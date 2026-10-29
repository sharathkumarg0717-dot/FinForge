<?php
declare(strict_types=1);

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = tempnam(sys_get_temp_dir(), 'finsync_chat_cookie_');

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

echo "=== FinSync AI Chat Interface & Styling Test ===\n";

// 1. Get Session & CSRF
echo "\n1. Fetching session & CSRF...\n";
$res = curlRequest("$baseUrl/api/session");
if ($res['code'] !== 200 || !isset($res['body']['data']['csrf_token'])) {
    throw new RuntimeException("Failed to get session. Code: {$res['code']}");
}
$csrf = $res['body']['data']['csrf_token'];
echo "   CSRF token retrieved.\n";

// 2. Register/Login test user
$testEmail = 'chat_tester_' . time() . '_' . bin2hex(random_bytes(4)) . '@example.com';
$password = 'Secret123!';
echo "\n2. Registering test user: $testEmail...\n";
$res = curlRequest("$baseUrl/api/register", 'POST', [
    'name' => 'Chat Tester',
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
echo "   Consent granted.\n";

// 4. Test Advisor API with spending question
echo "\n4. Testing /api/advisor with question: 'Summarize my spending this month'...\n";
$res = curlRequest("$baseUrl/api/advisor", 'POST', [
    'question' => 'Summarize my spending this month'
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 200) {
    throw new RuntimeException("Advisor API failed: {$res['raw_body']}");
}
$answer = $res['body']['data']['answer'] ?? '';
$disclaimer = $res['body']['data']['disclaimer'] ?? '';
echo "   Response answer: $answer\n";
echo "   Response disclaimer: $disclaimer\n";
if (empty($answer) || empty($disclaimer)) {
    throw new RuntimeException("Missing answer or disclaimer in advisor response!");
}

// 5. Test Advisor API with savings/cash question
echo "\n5. Testing /api/advisor with question: 'How is my cash flow?'...\n";
$res = curlRequest("$baseUrl/api/advisor", 'POST', [
    'question' => 'How is my cash flow?'
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 200) {
    throw new RuntimeException("Advisor API failed: {$res['raw_body']}");
}
echo "   Response answer: {$res['body']['data']['answer']}\n";

// 6. Test Advisor API with empty question (should fail validation)
echo "\n6. Testing /api/advisor with empty question (validation check)...\n";
$res = curlRequest("$baseUrl/api/advisor", 'POST', [
    'question' => ''
], ["X-CSRF-Token: $csrf"]);

if ($res['code'] !== 400 && $res['code'] !== 422) {
    throw new RuntimeException("Expected 400/422 for empty question, got: {$res['code']}");
}
echo "   Correctly returned {$res['code']}: {$res['body']['error']['message']}\n";

// 7. Verify CSS Styling for AI Chat
echo "\n7. Verifying CSS styling rules in public/assets/app.css...\n";
$css = file_get_contents(dirname(__DIR__) . '/public/assets/app.css');

$checks = [
    'User bubble text color dark' => (bool)preg_match('/\.user-bubble\s*>\s*div\s*\{[^}]*color:\s*#0f172a/s', $css),
    'User bubble p color dark' => (bool)preg_match('/\.user-bubble\s+p\s*\{[^}]*color:\s*#0f172a\s*!important/s', $css),
    'User bubble b header color' => (bool)preg_match('/\.user-bubble\s+b\s*\{[^}]*color:\s*#1e3a8a\s*!important/s', $css),
    'Assistant bubble text color dark' => (bool)preg_match('/\.chat-bubble\s*>\s*div\s*\{[^}]*color:\s*#0f172a/s', $css),
    'Chat bubble p color dark' => (bool)preg_match('/\.chat-bubble\s+p\s*\{[^}]*color:\s*#0f172a\s*!important/s', $css),
    'Chat bubble disclaimer dark readable' => (bool)preg_match('/\.chat-bubble\s+small\s*\{[^}]*color:\s*#334155\s*!important/s', $css),
    'Pending loading bubble text dark' => (bool)preg_match('/\.pending-bubble\s+p\s*\{[^}]*color:\s*#334155\s*!important/s', $css),
    'Error bubble text distinct dark red' => (bool)preg_match('/\.error-bubble\s+\.chat-error-text\s*\{[^}]*color:\s*#b91c1c\s*!important/s', $css),
    'Chat form input color dark' => (bool)preg_match('/\.chat-form\s+input\s*\{[^}]*color:\s*#0f172a\s*!important/s', $css),
    'Chat form placeholder distinct readable' => (bool)preg_match('/\.chat-form\s+input::placeholder\s*\{[^}]*color:\s*#64748b\s*!important/s', $css),
    'Chat form primary button styling' => (bool)preg_match('/\.chat-form\s+\.primary-button\s*\{[^}]*color:\s*#ffffff\s*!important/s', $css),
    'Empty state text dark readable' => (bool)preg_match('/\.empty-state\s*\{[^}]*color:\s*#0f172a\s*!important/s', $css),
    'Empty state p text dark readable' => (bool)preg_match('/\.empty-state\s+p\s*\{[^}]*color:\s*#334155\s*!important/s', $css),
];

foreach ($checks as $name => $passed) {
    if (!$passed) {
        throw new RuntimeException("CSS verification check failed: '$name'");
    }
    echo "   [PASS] $name\n";
}

// 8. Verify JavaScript handling in public/assets/app.js
echo "\n8. Verifying JS handling in public/assets/app.js...\n";
$js = file_get_contents(dirname(__DIR__) . '/public/assets/app.js');

if (strpos($js, 'pending.classList.add(\'error-bubble\')') === false) {
    throw new RuntimeException("Missing error-bubble handling in submitAdvisor!");
}
echo "   [PASS] submitAdvisor renders readable error-bubble on failure.\n";

if (strpos($js, 'submitBtn.disabled = true') === false || strpos($js, 'submitBtn.disabled = false') === false) {
    throw new RuntimeException("Missing submit button disabled toggle in submitAdvisor!");
}
echo "   [PASS] submitAdvisor manages button disabled state.\n";

// Clean up
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n>>> ALL AI CHAT INTERFACE & STYLING TESTS PASSED! <<<\n";

